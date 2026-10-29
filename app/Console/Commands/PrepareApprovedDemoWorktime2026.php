<?php

namespace App\Console\Commands;

use App\Domain\Audit\HrAuditWriter;
use App\Domain\Worktime\ProductionCalendarWriter;
use App\Domain\Worktime\ScheduleCyclePlanner;
use App\Domain\Worktime\WorkforceAuthorization;
use App\Http\Controllers\Api\V1\WorkSchedulePatternController;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * One-time, auditable preparation of approved demo cycles for November-December 2026.
 * Uses the same domain services, validations and permissions as the connected UI.
 * Never writes actual attendance and never alters historical production calendars.
 */
final class PrepareApprovedDemoWorktime2026 extends Command
{
    protected $signature = 'hrm:prepare-demo-worktime-2026
        {mode : patterns, calendar, or plans}
        {--apply : Commit changes. Omit for a read-only preflight}
        {--backup= : Fresh HRM custom-format database dump, required for apply}
        {--target= : Must equal itp-hrm-approved-demo-worktime-2026}';

    protected $description = 'Prepare 2026 worktime cycle patterns, GE calendar, and future plans using HRM domain logic';

    private const FROM = '2026-11-01';
    private const TO = '2026-12-31';
    private const TARGET = 'itp-hrm-approved-demo-worktime-2026';
    private const COMPANIES = ['ITP Georgia', 'ITP Mining', 'ITP Solutions'];
    private const SCHEDULE_CODES = ['SHIFT-2X2', 'STD-5X2', 'demo26_office'];

    public function handle(
        WorkforceAuthorization $access,
        HrAuditWriter $audit,
        ScheduleCyclePlanner $planner,
        ProductionCalendarWriter $calendarWriter,
    ): int {
        $mode = (string) $this->argument('mode');
        if (!in_array($mode, ['patterns', 'calendar', 'plans'], true)
            || $this->option('target') !== self::TARGET) {
            throw new RuntimeException('Expected exact approved target and one supported mode');
        }
        if (config('database.default') !== 'pgsql'
            || config('database.connections.pgsql.host') !== '127.0.0.1'
            || (string) config('database.connections.pgsql.port') !== '55432'
            || config('database.connections.pgsql.database') !== 'hrm'
            || config('database.connections.pgsql.search_path') !== 'hrm') {
            throw new RuntimeException('Refusing a database outside the isolated HRM production instance');
        }
        $database = DB::selectOne('select current_database() as db,current_schema() as schema_name,current_user as actor,inet_server_port() as port');
        if (!$database || $database->db !== 'hrm' || $database->schema_name !== 'hrm'
            || (int) $database->port !== 55432 || $database->actor !== 'hrm_app') {
            throw new RuntimeException('Unexpected PostgreSQL connection identity');
        }
        if (!config('hrm.worktime_writes_enabled') || !config('hrm.worktime_planning_enabled')
            || !config('hrm.worktime_matrix_enabled')) {
            throw new RuntimeException('Existing HRM worktime feature flags must be enabled');
        }
        $apply = (bool) $this->option('apply');
        if ($apply) $this->verifyBackup((string) $this->option('backup'));

        $companies = DB::table('companies')->whereIn('name', self::COMPANIES)
            ->orderBy('name')->get(['id', 'name']);
        if ($companies->count() !== 3 || $companies->pluck('name')->all() !== self::COMPANIES) {
            throw new RuntimeException('Demo company names are not the approved exact set');
        }
        $actors = [];
        foreach ($companies as $company) {
            $id = DB::table('company_user_roles')->where('company_id', $company->id)
                ->where('role_code', 'company_admin')->value('user_id');
            if (!$id) throw new RuntimeException('Missing approved company administrator role');
            $actors[$company->id] = User::query()->findOrFail($id);
            if (!$access->canEditCalendar($actors[$company->id], $company->id)
                || !$access->canEditTimesheet($actors[$company->id], $company->id)) {
                throw new RuntimeException('An existing company admin must pass all domain permission checks');
            }
        }
        $schedules = DB::table('work_schedules')->whereIn('company_id', $companies->pluck('id'))
            ->orderBy('company_id')->orderBy('code')->get();
        if ($schedules->count() !== 8 || $schedules->where('code','SHIFT-2X2')->count() !== 3
            || $schedules->where('code','STD-5X2')->count() !== 3
            || $schedules->where('code','demo26_office')->count() !== 2) {
            throw new RuntimeException('Demo schedule configuration has changed; inspect before continuing');
        }

        if ($mode === 'patterns') {
            $summary = [];
            $write = function () use ($schedules, $actors, $access, $audit, $apply, &$summary): void {
                foreach ($schedules as $schedule) {
                    [$policy, $days] = $this->template($schedule);
                    $latest = DB::table('work_schedule_patterns')
                        ->where('work_schedule_id', $schedule->id)->orderByDesc('version')->first();
                    if ($latest !== null) {
                        $actual = json_decode($latest->days, true, 512, JSON_THROW_ON_ERROR);
                        if ($policy !== $latest->calendar_policy || $actual !== $days) {
                            throw new RuntimeException('Existing template differs from approved demo rules; no overwrite allowed');
                        }
                        $summary[] = ['code' => $schedule->code, 'status' => 'already-correct'];
                        continue;
                    }
                    $summary[] = ['code' => $schedule->code, 'status' => $apply ? 'created' : 'will-create'];
                    if (!$apply) continue;
                    $request = Request::create('/internal/demo-worktime-pattern', 'PUT', [
                        'revision' => (int) $schedule->revision, 'calendar_policy' => $policy, 'days' => $days,
                    ]);
                    $request->setUserResolver(fn (): User => $actors[$schedule->company_id]);
                    app(WorkSchedulePatternController::class)->update(
                        $request, $schedule->company_id, $schedule->id, $access, $audit,
                    );
                }
            };
            if ($apply) DB::transaction($write, 1); else $write();
            $this->line(json_encode(['mode'=>'patterns','apply'=>$apply,'templates'=>$summary], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            return self::SUCCESS;
        }

        if ($mode === 'calendar') {
            $company = $companies->firstWhere('name', 'ITP Georgia');
            $actor = $actors[$company->id];
            $months = [];
            foreach (['2026-11','2026-12'] as $month) {
                $days = [];
                $start = CarbonImmutable::parse($month.'-01');
                for ($date=$start; $date->format('Y-m') === $month; $date=$date->addDay()) {
                    $day = $date->toDateString();
                    $holiday = $day === '2026-11-23';
                    $weekend = $date->isWeekend();
                    $days[] = [
                        'date' => $day,
                        'day_type' => $holiday ? 'holiday' : ($weekend ? 'weekend' : 'workday'),
                        'planned_minutes' => $holiday || $weekend ? 0 : 480,
                        'label' => $holiday ? 'Гиоргоба / გიორგობა (Georgia Labour Code, Article 30)' : null,
                        'revision' => null,
                    ];
                }
                $existing = DB::table('production_calendar_days')->where('company_id', $company->id)
                    ->whereBetween('work_date', [$days[0]['date'], end($days)['date']])->get();
                if ($existing->count() > 0) {
                    if ($existing->count() !== count($days)) throw new RuntimeException('Georgia calendar month is partially set. Resolve manually.');
                    foreach ($existing as $row) {
                        $want = collect($days)->firstWhere('date', $row->work_date);
                        if ($want === null || $row->day_type !== $want['day_type']
                            || (int) $row->planned_minutes !== (int) $want['planned_minutes']) {
                            throw new RuntimeException('Georgia calendar differs from legal holiday baseline. Resolve manually.');
                        }
                    }
                    $months[] = ['month'=>$month, 'status'=>'already-correct'];
                    continue;
                }
                $months[] = ['month'=>$month, 'days'=>count($days),
                    'status'=>$apply ? 'created' : 'will-create'];
                if ($apply) $calendarWriter->upsert($actor, $company->id, $month, $days);
            }
            $this->line(json_encode(['mode'=>'calendar','apply'=>$apply,'months'=>$months], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            return self::SUCCESS;
        }

        // Future-only plans. No historical actuals are modified or copied.
        if (CarbonImmutable::today()->greaterThan(CarbonImmutable::parse(self::FROM))) {
            throw new RuntimeException('Approved November 2026 start is now in the past');
        }
        $jobs = [];
        $seen = [];
        foreach ($schedules as $schedule) {
            $pattern = DB::table('work_schedule_patterns')
                ->where('work_schedule_id', $schedule->id)->orderByDesc('version')->first();
            if ($pattern === null) throw new RuntimeException('Missing template; apply patterns mode first');
            $ids = DB::table('employee_work_schedules')
                ->where('work_schedule_id', $schedule->id)
                ->where('valid_from', '<=', self::FROM)
                ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', self::TO))
                ->orderBy('employee_id')->pluck('employee_id')->all();
            if (!$ids) throw new RuntimeException('Expected non-empty demo schedule assignments');
            foreach ($ids as $id) {
                if (isset($seen[$id])) throw new RuntimeException('An employee has overlapping schedule assignments');
                $seen[$id] = true;
            }
            $groups = [];
            if ($schedule->schedule_type === 'shift') {
                foreach ($ids as $i => $id) $groups[$i % 2 === 0 ? 0 : 2][] = $id;
            } else {
                $groups[0] = $ids;
            }
            foreach ($groups as $offset => $selected) {
                $input = [
                    'employee_ids' => $selected, 'from'=>self::FROM, 'to'=>self::TO,
                    'schedule_revision'=>(int)$schedule->revision, 'cycle_offset'=>(int)$offset,
                    'replace_future_plans'=>true, 'apply'=>false,
                ];
                $preview = $planner->run($actors[$schedule->company_id], $schedule->company_id, $schedule->id, $input);
                if (($preview['meta']['can_apply'] ?? false) !== true
                    || ($preview['data']['blocking_count'] ?? -1) !== 0
                    || ($preview['data']['new_days'] ?? 0) !== count($selected)*61) {
                    throw new RuntimeException('Planning preview has blocking conditions for '.$schedule->code);
                }
                $jobs[] = compact('schedule','input','preview');
            }
        }
        if (count($seen) !== 104) throw new RuntimeException('Expected exactly 104 distinct roster employees');
        $prior = DB::table('worktime_entries')->count();
        $plannedBefore = DB::table('employee_work_plans')
            ->whereBetween('work_date',[self::FROM,self::TO])->count();
        if ($plannedBefore !== 0) throw new RuntimeException('Planning target already contains rows. Do not overwrite.');
        $summary = array_map(static fn (array $job): array => [
            'schedule'=>$job['schedule']->code,
            'employee_count'=>$job['preview']['data']['employee_count'],
            'planned_days'=>$job['preview']['data']['new_days'],
            'blocking_count'=>$job['preview']['data']['blocking_count'],
        ],$jobs);
        if ($apply) {
            DB::transaction(function () use ($jobs,$planner,$actors): void {
                foreach ($jobs as $job) {
                    $schedule = $job['schedule'];
                    $input = $job['input'];
                    $preview = $job['preview'];
                    $applied = $planner->run($actors[$schedule->company_id], $schedule->company_id,
                        $schedule->id, [...$input, 'apply'=>true, 'confirm'=>true,
                            'preview_token'=>$preview['meta']['preview_token']]);
                    if (($applied['meta']['mode'] ?? null) !== 'applied') {
                        throw new RuntimeException('Domain service did not confirm plan application');
                    }
                }
            },1);
            if (DB::table('employee_work_plans')->whereBetween('work_date',[self::FROM,self::TO])->count() !== 104*61) {
                throw new RuntimeException('Post-commit count mismatch; investigate without rewriting actuals');
            }
            if (DB::table('worktime_entries')->count() !== $prior) {
                throw new RuntimeException('Actual attendance count unexpectedly changed');
            }
        }
        $this->line(json_encode([
            'mode'=>'plans','apply'=>$apply,'employees'=>count($seen),
            'days_per_employee'=>61,'expected_plan_rows'=>104*61,
            'actual_attendance_rows_before'=>$prior,'jobs'=>$summary,
        ],JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        return self::SUCCESS;
    }

    private function template(object $schedule): array
    {
        if ($schedule->code === 'SHIFT-2X2' && $schedule->schedule_type === 'shift'
            && (int)$schedule->weekly_minutes === 2520) {
            return ['cycle',[
                ['minutes'=>720,'shift_code'=>'D'], ['minutes'=>720,'shift_code'=>'D'],
                ['minutes'=>0,'shift_code'=>null], ['minutes'=>0,'shift_code'=>null],
            ]];
        }
        if (in_array($schedule->code,['STD-5X2','demo26_office'],true)
            && $schedule->schedule_type === 'standard'
            && (int)$schedule->weekly_minutes === 2400) {
            return ['company',array_map(
                static fn (int $i): array => ['minutes'=>$i<5?480:0,'shift_code'=>null],
                range(0,6),
            )];
        }
        throw new RuntimeException('Unrecognized existing schedule type or work hour total');
    }

    private function verifyBackup(string $path): void
    {
        $root=realpath('C:/deploy/hrm/postgres-isolated/backups');
        $file=realpath($path);
        if (!$root || !$file || dirname($file) !== $root || !str_ends_with($file,'.dump')
            || filesize($file) < 100000 || filemtime($file) < time()-14400) {
            throw new RuntimeException('A fresh verified custom-format HRM backup is required');
        }
        $this->line('BACKUP_SHA256='.strtoupper(hash_file('sha256',$file)));
    }
}

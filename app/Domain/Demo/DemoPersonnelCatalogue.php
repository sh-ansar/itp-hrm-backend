<?php

namespace App\Domain\Demo;

use App\Domain\Audit\HrAuditWriter;
use App\Domain\Personnel\AssignmentPeriodPolicy;
use App\Domain\Worktime\WorkforceAuthorization;
use App\Http\Controllers\Api\V1\DepartmentRecordController;
use App\Http\Controllers\Api\V1\EmployeeRecordController;
use App\Http\Controllers\Api\V1\PersonnelActionController;
use App\Http\Controllers\Api\V1\PositionRecordController;
use App\Http\Controllers\Api\V1\WorkScheduleWriteController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/** Bounded additive catalogue for the explicitly approved customer demonstration. */
final class DemoPersonnelCatalogue
{
    public const TARGETS = ['ITP Mining' => 80, 'ITP Solutions' => 20];
    public const PREFIX = 'DEMO26-';
    private const DEPARTMENTS = [
        ['Департамент корпоративного управления и развития', null],
        ['Департамент IT и цифровых решений', null],
        ['Департамент операционной и финансовой деятельности', null],
        ['Разработка программного обеспечения', 1],
        ['Инфраструктура, DevOps и IT-поддержка', 1],
        ['Продукт, UX/UI и аналитика', 1],
        ['Бухгалтерия и финансы', 2],
        ['Закупки и договорная работа', 2],
        ['Персонал и административное управление', 0],
        ['Проекты и операционная деятельность', 0],
    ];
    private const JOBS = ['Разработчик', 'DevOps-инженер', 'UX/UI-дизайнер', 'Бухгалтер', 'Специалист по закупкам', 'HR-специалист', 'Руководитель проекта'];

    private function request(User $actor, array $data): Request
    {
        $request = Request::create('/', 'POST', $data);
        $request->setUserResolver(fn () => $actor);
        return $request;
    }

    public function apply(User $actor): array
    {
        return DB::transaction(function () use ($actor): array {
            // A stable lock order serializes repeat/concurrent invocations.
            $companies = DB::table('companies')->whereIn('name', array_keys(self::TARGETS))
                ->orderBy('id')->lockForUpdate()->get();
            if ($companies->count() !== 2 || $companies->pluck('name')->unique()->count() !== 2) {
                throw new RuntimeException('Exactly one existing Mining and Solutions company is required.');
            }
            $summary = [];
            $audit = app(HrAuditWriter::class);
            $authorization = app(WorkforceAuthorization::class);
            foreach ($companies as $company) {
                $role = DB::table('company_user_roles')->where('company_id', $company->id)->where('user_id', $actor->id)->value('role_code');
                if ($role !== 'company_admin' || $actor->account_status->value !== 'active') {
                    throw new RuntimeException('An active company administrator must own both target companies.');
                }
                $active = DB::table('employees')->where('company_id', $company->id)->whereExists(function ($query): void {
                    $query->selectRaw('1')->from('employee_assignments')->whereColumn('employee_id', 'employees.id')
                        ->where('valid_from', '<=', today()->toDateString())
                        ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', today()->toDateString()));
                })->count();
                $target = self::TARGETS[$company->name];
                if ($active > $target) throw new RuntimeException('Existing active count exceeds the approved target; no data was changed.');
                $missing = $target - $active;
                if ($missing === 0) {
                    $summary[$company->name] = ['active' => $active, 'created' => 0];
                    continue;
                }
                $prefix = self::PREFIX.($company->name === 'ITP Mining' ? 'M-' : 'S-');
                // Never adopt or overwrite a conflicting personnel number.
                for ($i = 1; $i <= $missing; $i++) {
                    if (DB::table('employees')->where('company_id', $company->id)->where('personnel_number', $prefix.sprintf('%03d', $i))->exists()) {
                        throw new RuntimeException('Demo personnel prefix collision; inspect existing records.');
                    }
                }
                $departments = [];
                $definitions = $company->name === 'ITP Mining' ? self::DEPARTMENTS : [
                    ['Цифровые решения', null], ['Разработка и поддержка', 0], ['Продукт и проекты', 0], ['Администрирование и финансы', 0],
                ];
                foreach ($definitions as $index => [$name, $parent]) {
                    $parentId = $parent === null ? null : $departments[$parent];
                    $existing = DB::table('departments')->where('company_id', $company->id)->where('name', $name)->get();
                    if ($existing->count() > 1 || ($existing->count() === 1 && $existing[0]->parent_id !== $parentId)) {
                        throw new RuntimeException('Department name/parent conflict; existing structure was preserved.');
                    }
                    $departments[$index] = $existing->count() ? $existing[0]->id : app(DepartmentRecordController::class)
                        ->store($this->request($actor, ['name' => $name, 'parent_id' => $parentId]), $company->id, $audit)->getData(true)['data']['id'];
                }
                $code = 'demo26_office';
                $schedule = DB::table('work_schedules')->where('company_id', $company->id)->where('code', $code)->value('id');
                $schedule ??= app(WorkScheduleWriteController::class)->store($this->request($actor, [
                    'code' => $code, 'name' => 'Офисный 5/2 — тестовый', 'schedule_type' => 'standard',
                    'work_time_label' => '09:00–18:00', 'weekly_minutes' => 2400,
                ]), $company->id, $authorization, $audit)->getData(true)['data']['id'];
                $leaders = [];
                for ($i = 1; $i <= $missing; $i++) {
                    $group = ($i - 1) % 7;
                    $department = $company->name === 'ITP Mining' ? $departments[$group + 3] : $departments[1 + ($group % 3)];
                    $isLead = !isset($leaders[$department]);
                    $job = $isLead ? 'Руководитель — '.($definitions[array_search($department, $departments, true)][0]) : self::JOBS[$group];
                    $position = DB::table('positions')->where('company_id', $company->id)->where('name', $job)->value('id');
                    $position ??= app(PositionRecordController::class)->store($this->request($actor, ['name' => $job]), $company->id, $audit)->getData(true)['data']['id'];
                    $last = ['Алимов', 'Белов', 'Волков', 'Давыдов', 'Егоров', 'Жуков', 'Зорин', 'Ильин', 'Ковалев', 'Ларин', 'Миронов', 'Нестеров', 'Орлов', 'Павлов', 'Романов'][($i - 1) % 15];
                    $first = ['Алексей', 'Арман', 'Данияр', 'Евгений', 'Илья', 'Марат', 'Никита'][intdiv($i - 1, 15) % 7];
                    $female = $i % 2 === 0;
                    if ($female) {
                        $last .= 'а';
                        $first = ['Алина', 'Мария', 'Елена', 'Ирина', 'Анна', 'Мадина', 'Дарья'][intdiv($i - 1, 15) % 7];
                    }
                    $name = $last.' '.$first;
                    $hired = sprintf('%d-%02d-10', 2021 + ($i % 5), 1 + ($i % 12));
                    $number = $prefix.sprintf('%03d', $i);
                    $employee = app(EmployeeRecordController::class)->store($this->request($actor, [
                        'personnel_number' => $number, 'last_name' => $last, 'first_name' => $first,
                        'birth_date' => sprintf('%d-%02d-15', 1985 + ($i % 15), 1 + ($i % 12)), 'gender' => $female ? 'female' : 'male',
                        'email' => strtolower($number).'@hrm.example.test', 'work_site' => 'Тестовый офис',
                        'work_team' => $definitions[array_search($department, $departments, true)][0],
                        'supervisor_name' => $isLead ? null : $leaders[$department],
                    ]), $company->id, $audit)->getData(true)['data']['id'];
                    $leaders[$department] ??= $name;
                    app(PersonnelActionController::class)->hire($this->request($actor, [
                        'department_id' => $department, 'position_id' => $position, 'effective_on' => $hired,
                        'basis_reference' => 'DEMO-ONLY-'.$number, 'notes' => 'Вымышленные демонстрационные данные, не реальное кадровое событие.',
                    ]), $company->id, $employee, app(AssignmentPeriodPolicy::class), $audit);
                    app(WorkScheduleWriteController::class)->assign($this->request($actor, ['valid_from' => $hired]),
                        $company->id, $schedule, $employee, $authorization, $audit);
                    // Explicit fixture records: no personal identifiers, files, salaries or mail.
                    $timestamps = ['created_at' => now(), 'updated_at' => now(), 'revision' => 1];
                    DB::table('employee_contracts')->insert([
                        'id' => (string) Str::uuid(), 'company_id' => $company->id, 'employee_id' => $employee,
                        'contract_number' => 'DEMO-'.$number, 'contract_type' => 'indefinite',
                        'valid_from' => $hired, 'signed_on' => $hired, 'basis_reference' => 'DEMO ONLY', ...$timestamps,
                    ]);
                    DB::table('employee_education_records')->insert([
                        'id' => (string) Str::uuid(), 'company_id' => $company->id, 'employee_id' => $employee,
                        'education_type' => 'Высшее (тестовые сведения)', 'organization' => 'Демонстрационный университет',
                        'specialization' => self::JOBS[$group], 'started_on' => '2003-09-01', 'ended_on' => '2007-06-30',
                        'document_reference' => 'DEMO ONLY — '.$number, ...$timestamps,
                    ]);
                    DB::table('employee_training')->insert([
                        'id' => (string) Str::uuid(), 'employee_id' => $employee, 'training_type' => 'seminar',
                        'training_mode' => 'remote', 'topic' => 'Демонстрационное обучение: работа в команде',
                        'provider' => 'Тестовый учебный центр', 'started_on' => '2026-02-02', 'ended_on' => '2026-02-03',
                        'document_reference' => 'DEMO ONLY', ...$timestamps,
                    ]);
                }
                $summary[$company->name] = ['active' => $target, 'created' => $missing];
            }
            return $summary;
        });
    }
}

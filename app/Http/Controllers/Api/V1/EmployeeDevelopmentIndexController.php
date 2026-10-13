<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\HrAuditAction;
use App\Domain\Audit\HrAuditWriter;
use App\Http\Controllers\Controller;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class EmployeeDevelopmentIndexController extends Controller
{
    /** Company-wide development records contain protected personnel data. */
    private const TABLES = [
        'training' => 'employee_training',
        'reserve' => 'employee_reserve',
    ];

    public function __invoke(
        Request $request,
        string $company,
        string $section,
        HrAuditWriter $audit,
    ): JsonResponse {
        abort_unless(isset(self::TABLES[$section]), 404);

        $role = DB::table('company_user_roles')
            ->where('company_id', $company)
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->value('role_code');
        abort_unless(in_array($role, ['company_admin', 'hr_specialist'], true), 403);

        $allowedStatuses = $section === 'training'
            ? ['all', 'planned', 'ongoing', 'completed']
            : ['all', 'active', 'alumni'];

        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', Rule::in($allowedStatuses)],
        ]);

        $today = now()->toDateString();
        $search = trim((string) ($validated['search'] ?? ''));
        $status = (string) ($validated['status'] ?? 'all');

        // Both employee identity and development record must belong to the
        // authorized company. No global list or department-manager fallback.
        $base = DB::table(self::TABLES[$section].' as d')
            ->join('employees as e', 'e.id', '=', 'd.employee_id')
            ->where('e.company_id', $company);

        // Summary is calculated across the entire company, never just this page.
        $summary = ['total' => (clone $base)->count()];
        foreach (array_slice($allowedStatuses, 1) as $state) {
            $summary[$state] = $this->forStatus(clone $base, $section, $state, $today)->count();
        }

        $query = $this->forStatus(clone $base, $section, $status, $today);
        if ($search !== '') {
            $pattern = '%'.$search.'%';
            $field = $section === 'training' ? 'd.topic' : 'd.target_position';
            $query->where(function (Builder $q) use ($pattern, $field): void {
                $q->whereLike('e.personnel_number', $pattern)
                    ->orWhereLike('e.last_name', $pattern)
                    ->orWhereLike('e.first_name', $pattern)
                    ->orWhereLike('e.middle_name', $pattern)
                    ->orWhereLike($field, $pattern);
            });
        }

        $query->select([
            'd.id', 'd.employee_id', 'd.revision',
            'e.personnel_number', 'e.last_name', 'e.first_name', 'e.middle_name',
        ]);

        if ($section === 'training') {
            $query->addSelect(
                'd.training_type', 'd.training_mode', 'd.topic',
                'd.provider', 'd.city', 'd.started_on', 'd.ended_on',
                'd.document_reference'
            )->selectRaw(
                "CASE WHEN d.started_on > ? THEN 'planned'
                      WHEN d.ended_on IS NOT NULL AND d.ended_on < ? THEN 'completed'
                      ELSE 'ongoing' END AS development_status",
                [$today, $today]
            )->orderByDesc('d.started_on');
        } else {
            // Deliberately exclude free-text notes from the company-wide list.
            $query->addSelect(
                'd.target_position', 'd.reserve_level', 'd.reserve_type',
                'd.joined_year', 'd.left_year'
            )->selectRaw(
                "CASE WHEN d.left_year IS NULL THEN 'active'
                      ELSE 'alumni' END AS development_status"
            )->orderByDesc('d.joined_year');
        }

        $page = $query->orderBy('e.last_name')
            ->orderBy('e.first_name')
            ->orderBy('d.id')
            ->paginate((int) ($validated['per_page'] ?? 25));

        // Never record search text, employee IDs, names, or record content.
        $audit->record($request->user(), $company, HrAuditAction::DevelopmentRecordsListed, null, [
            'section' => $section,
            'status_filter' => $status,
            'search_applied' => $search !== '',
            'returned_count' => count($page->items()),
            'page' => $page->currentPage(),
        ]);

        return response()->json([
            'data' => $page->items(),
            'meta' => [
                'section' => $section,
                'status_filter' => $status,
                'summary' => $summary,
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'profile_writes_enabled' => (bool) config('hrm.personnel_writes_enabled', false),
            ],
        ]);
    }

    private function forStatus(Builder $query, string $section, string $status, string $today): Builder
    {
        if ($section === 'reserve') {
            return match ($status) {
                'active' => $query->whereNull('d.left_year'),
                'alumni' => $query->whereNotNull('d.left_year'),
                default => $query,
            };
        }

        return match ($status) {
            'planned' => $query->where('d.started_on', '>', $today),
            'ongoing' => $query->where('d.started_on', '<=', $today)
                ->where(function (Builder $q) use ($today): void {
                    $q->whereNull('d.ended_on')->orWhere('d.ended_on', '>=', $today);
                }),
            'completed' => $query->whereNotNull('d.ended_on')->where('d.ended_on', '<', $today),
            default => $query,
        };
    }
}

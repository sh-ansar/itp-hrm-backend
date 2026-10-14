<?php

namespace App\Domain\Worktime;

use App\Domain\Audit\HrAuditAction;
use App\Domain\Audit\HrAuditWriter;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Serializes company calendar changes via a company-row lock, then validates
 * every expected revision before writing a single day. No partial imports.
 *
 * Lock ordering is consistent with other company-scoped write operations.
 */
final class ProductionCalendarWriter
{
    public function __construct(private readonly HrAuditWriter $audit) {}

    public function upsert(User $actor, string $companyId, string $month, array $days): array
    {
        return DB::transaction(function () use ($actor, $companyId, $month, $days): array {
            DB::table('companies')->where('id', $companyId)->lockForUpdate()->firstOrFail();

            $dates = array_column($days, 'date');
            $existing = DB::table('production_calendar_days')
                ->where('company_id', $companyId)
                ->whereIn('work_date', $dates)
                ->get()
                ->keyBy('work_date');

            // Validate all day revisions before any mutation or audit entry.
            foreach ($days as $day) {
                $current = $existing->get($day['date']);
                $expected = $day['revision'];
                if (($current === null && $expected !== null)
                    || ($current !== null && ($expected === null || (int) $current->revision !== $expected))) {
                    abort(409, 'Calendar changed since it was loaded. Refresh the month before saving.');
                }
            }

            $createdCount = 0;
            $updatedCount = 0;
            foreach ($days as $day) {
                $current = $existing->get($day['date']);
                $values = [
                    'day_type' => $day['day_type'],
                    'planned_minutes' => $day['planned_minutes'],
                    'label' => $day['label'],
                    'updated_at' => now(),
                ];
                if ($current === null) {
                    DB::table('production_calendar_days')->insert(array_merge($values, [
                        'id' => (string) Str::uuid(),
                        'company_id' => $companyId,
                        'work_date' => $day['date'],
                        'revision' => 1,
                        'created_at' => now(),
                    ]));
                    $createdCount++;
                    continue;
                }

                $updated = DB::table('production_calendar_days')
                    ->where('id', $current->id)
                    ->where('company_id', $companyId)
                    ->where('revision', (int) $current->revision)
                    ->update(array_merge($values, ['revision' => (int) $current->revision + 1]));
                abort_unless($updated === 1, 409, 'Calendar was modified by another user.');
                $updatedCount++;
            }

            // The audit is in the SAME transaction. Never record employees/PII in its context.
            $this->audit->record($actor, $companyId, HrAuditAction::WorktimeCalendarDaysUpserted, null, [
                'month' => $month,
                'dates' => $dates,
                'created_count' => $createdCount,
                'updated_count' => $updatedCount,
            ]);

            $saved = DB::table('production_calendar_days')
                ->where('company_id', $companyId)
                ->whereIn('work_date', $dates)
                ->select('id', 'work_date', 'day_type', 'planned_minutes', 'label', 'revision')
                ->orderBy('work_date')
                ->get()
                ->map(static fn (object $row): array => [
                    'id' => $row->id,
                    'date' => $row->work_date,
                    'day_type' => $row->day_type,
                    'planned_minutes' => $row->planned_minutes !== null ? (int) $row->planned_minutes : null,
                    'label' => $row->label,
                    'revision' => (int) $row->revision,
                ])
                ->all();

            return [
                'data' => $saved,
                'meta' => ['month' => $month, 'created_count' => $createdCount, 'updated_count' => $updatedCount],
            ];
        }, 3);
    }
}

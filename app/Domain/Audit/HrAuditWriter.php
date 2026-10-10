<?php

namespace App\Domain\Audit;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class HrAuditWriter
{
    /**
     * Append-only application audit. Call from the same DB transaction as
     * protected writes. Context must be built from server-approved fields.
     */
    public function record(
        User $actor,
        string $companyId,
        HrAuditAction $action,
        ?int $subjectUserId = null,
        array $context = [],
    ): void {
        DB::table('hr_audit_events')->insert([
            'company_id' => $companyId,
            'actor_user_id' => (int) $actor->getAuthIdentifier(),
            'subject_user_id' => $subjectUserId,
            'action' => $action->value,
            'context' => json_encode($context, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }
}

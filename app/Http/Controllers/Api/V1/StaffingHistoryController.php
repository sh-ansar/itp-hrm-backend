<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\HrAuditAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class StaffingHistoryController extends Controller
{
    public function __invoke(Request $request, string $company): JsonResponse
    {
        $role = DB::table('company_user_roles')
            ->where('company_id', $company)
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->value('role_code');

        abort_unless(in_array($role, ['company_admin', 'hr_specialist'], true), 403);

        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $actions = [
            HrAuditAction::StaffingPlanCreated->value,
            HrAuditAction::StaffingPlanUpdated->value,
            HrAuditAction::StaffingPlanLineCreated->value,
            HrAuditAction::StaffingPlanLineUpdated->value,
            HrAuditAction::StaffingPlanApproved->value,
        ];

        $page = DB::table('hr_audit_events')
            ->where('company_id', $company)
            ->whereIn('action', $actions)
            ->select('id', 'action', 'context', 'created_at')
            ->orderByDesc('id')
            ->paginate((int) ($validated['per_page'] ?? 25));

        $rows = collect($page->items())->map(static function (object $event): array {
            $context = json_decode((string) $event->context, true);
            if (!is_array($context)) $context = [];

            return [
                'id' => $event->id,
                'action' => $event->action,
                'staffing_plan_id' => $context['staffing_plan_id'] ?? null,
                'staffing_line_id' => $context['staffing_plan_line_id'] ?? ($context['line_id'] ?? null),
                'revision' => $context['revision'] ?? null,
                'created_at' => $event->created_at,
            ];
        });

        return response()->json([
            'data' => $rows,
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]);
    }
}

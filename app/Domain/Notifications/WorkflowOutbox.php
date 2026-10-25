<?php

namespace App\Domain\Notifications;

use App\Domain\Personnel\EmployeeVisibility;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Transactional event/recipient projection. Never performs network IO. */
final class WorkflowOutbox
{
    private const EVENTS = [
        'system.error_observed' => 'employee_changes',
        'leave.request_submitted' => 'schedule_changes',
        'leave.request_manager_approved' => 'schedule_changes',
        'leave.request_manager_rejected' => 'schedule_changes',
        'leave.request_approved' => 'schedule_changes',
        'leave.request_rejected' => 'schedule_changes',
        'leave.approved_request_reversed' => 'schedule_changes',
        'personnel.order_submitted' => 'document_deadlines',
        'personnel.order_approved' => 'document_deadlines',
        'personnel.order_rejected' => 'document_deadlines',
        'personnel.action_completed' => 'employee_changes',
        'worktime.period_submitted' => 'schedule_changes',
        'worktime.period_approved' => 'schedule_changes',
        'worktime.period_reopened' => 'schedule_changes',
    ];

    public function append(int $audit, User $actor, string $company, string $event, array $context): void
    {
        if (!config('hrm.workflow_notifications_enabled', false) || !isset(self::EVENTS[$event])) return;
        $employee = $context['employee_id'] ?? null;
        $creator = null;
        if (str_starts_with($event, 'leave.') && isset($context['request_id'])) {
            $request = DB::table('leave_requests')->where('company_id', $company)
                ->where('id', $context['request_id'])->first(['employee_id', 'created_by_user_id']);
            $employee = $request?->employee_id;
            $creator = $request?->created_by_user_id;
        }
        // Only authorized HR/admin recipients, plus precisely scoped reviewers
        // and the subject's explicitly linked account. No company-wide broadcast.
        $users = User::query()->where('account_status', 'active')->whereIn('id', DB::table('company_user_roles')
            ->where('company_id', $company)->select('user_id'))->get();
        $correlation = request()->attributes->get('hrm.request_id') ?: (string) Str::uuid();
        foreach ($users as $user) {
            if ((int) $user->id === (int) $actor->id) continue;
            $role = DB::table('company_user_roles')->where('company_id', $company)
                ->where('user_id', $user->id)->value('role_code');
            $target = in_array($role, ['company_admin', 'hr_specialist'], true);
            if ($event === 'leave.request_submitted' && config('hrm.leave_manager_approval_enabled', false)) {
                $target = $role === 'department_manager' && $employee
                    && app(EmployeeVisibility::class)->visibleFor($user, $company)->where('employees.id', $employee)->exists();
            } elseif (str_starts_with($event, 'leave.') && $event !== 'leave.request_submitted') {
                $target = $target || (int) $creator === (int) $user->id
                    || ($employee && DB::table('employee_user_links')->where('company_id', $company)
                        ->where('employee_id', $employee)->where('user_id', $user->id)->exists());
            }
            if (!$target) continue;
            $pref = $this->preferences((int) $user->id);
            if (!($pref['rules'][self::EVENTS[$event]] ?? true)) continue;
            DB::table('hrm_notifications')->insertOrIgnore([
                'id' => (string) Str::uuid(), 'audit_event_id' => $audit, 'company_id' => $company,
                'user_id' => $user->id, 'employee_id' => $employee, 'event' => $event,
                'rule' => self::EVENTS[$event], 'locale' => $pref['locale'], 'correlation_id' => $correlation,
                'in_app' => $pref['channels']['in_app'] ?? true,
                'mail_state' => ($pref['channels']['email'] ?? true) ? 'pending' : 'cancelled',
                'available_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function preferences(int $user): array
    {
        $row = DB::table('user_preferences')->where('user_id', $user)->first();
        return [
            'locale' => in_array($row?->locale, ['ru', 'kk', 'en', 'ka'], true) ? $row->locale : 'ru',
            'channels' => $row ? (array) json_decode($row->notification_channels, true) : [],
            'rules' => $row ? (array) json_decode($row->notification_rules, true) : [],
        ];
    }

    /** Recheck membership and scope immediately before network delivery. */
    public function stillAuthorized(object $item, User $user): bool
    {
        if ($user->account_status->value !== 'active') return false;
        $role = DB::table('company_user_roles')->where('company_id', $item->company_id)
            ->where('user_id', $user->id)->value('role_code');
        if (in_array($role, ['company_admin', 'hr_specialist'], true)) return true;
        if (!$role) return false;
        if ($role === 'department_manager' && $item->event === 'leave.request_submitted' && $item->employee_id) {
            return config('hrm.leave_manager_approval_enabled', false)
                && app(EmployeeVisibility::class)->visibleFor($user, $item->company_id)
                    ->where('employees.id', $item->employee_id)->exists();
        }
        return $item->employee_id && str_starts_with($item->event, 'leave.')
            && $item->event !== 'leave.request_submitted'
            && DB::table('employee_user_links')->where('company_id', $item->company_id)
                ->where('user_id', $user->id)->where('employee_id', $item->employee_id)->exists();
    }
}

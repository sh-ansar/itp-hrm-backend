<?php

namespace App\Http\Middleware;

use App\Domain\Identity\CompanyRole;
use App\Domain\Identity\CustomRolePermissions;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mandatory additional API gate for configured company roles.
 *
 * Existing canonical Laravel authorizers remain authoritative and can only
 * further RESTRICT a request. A custom role cannot expand canonical access.
 * An unfamiliar company route is denied, not silently allowed.
 */
final class EnforceCompanyRoleTemplate
{
    public function __construct(private readonly CustomRolePermissions $permissions)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $company = $request->route('company');
        $user = $request->user();
        if (!is_string($company) || $user === null) {
            return $next($request);
        }

        $role = $this->permissions->assignedTemplate((int) $user->getAuthIdentifier(), $company);
        if ($role === null) {
            return $next($request);
        }

        $base = CompanyRole::tryFrom($role->base_role);
        if ($base === null || $base === CompanyRole::CompanyAdmin) {
            throw new AuthorizationException('Invalid company role template.');
        }

        $prefix = 'api/v1/companies/'.$company.'/';
        $uri = $request->path();
        if (!str_starts_with($uri, $prefix)) {
            throw new AuthorizationException('Unknown company route.');
        }

        $resource = substr($uri, strlen($prefix));
        // The employee's own workspace is separately bound to the same
        // authenticated identity; roles cannot expose another user's /me.
        if (str_starts_with($resource, 'me/')) {
            return $next($request);
        }

        $required = $this->requiredPermission($request, $resource);
        $effective = $this->permissions->effective($base, $role->capabilities);
        if ($required === null || ($effective[$required] ?? false) !== true) {
            throw new AuthorizationException('This permission is disabled for the assigned company role.');
        }

        if (str_starts_with($resource, 'leave-requests/')
            && str_contains($resource, '/evidence/')
            && ($effective['leaves.evidence.view'] ?? false) !== true) {
            throw new AuthorizationException('Protected leave evidence access is not granted.');
        }

        return $next($request);
    }

    private function requiredPermission(Request $request, string $resource): ?string
    {
        $parts = explode('/', $resource);
        $section = $parts[0];
        $isRead = $request->isMethod('GET') || $request->isMethod('HEAD');

        if (in_array($section, ['role-templates', 'memberships'], true)) {
            return null; // Only canonical company_admin may manage roles.
        }

        return match ($section) {
            'employees' => $this->employeePermission($parts, $isRead),
            'development' => $isRead ? 'development.view' : 'employees.write',
            'departments' => $isRead ? 'organization.view' : 'staffing.write',
            'positions', 'directories' => $isRead ? 'directories.view' : 'directories.write',
            'staffing-plans', 'staffing-history' => $isRead ? 'staffing.view' : 'staffing.write',
            'personnel-actions' => $isRead ? 'personnel_actions.view' : 'employees.write',
            'worktime' => $this->worktimePermission($parts, $isRead),
            'leaves', 'leave-requests', 'leave-balances', 'leave-types' =>
                $this->leavePermission($parts, $isRead),
            'documents' => $isRead ? 'documents.view' : 'documents.write',
            'bonuses' => $isRead ? 'bonuses.view'
                : (in_array('decision', $parts, true) ? 'bonuses.approve' : 'bonuses.manage'),
            default => null, // Deny unknown data paths for custom roles.
        };
    }

    private function employeePermission(array $parts, bool $read): string
    {
        if (in_array('personnel-actions', $parts, true)) {
            return 'employees.write';
        }

        // Reads remain additionally scoped by EmployeeVisibility and the
        // protected-record controllers (including medical and identity data).
        return $read ? 'employees.view' : 'employees.write';
    }

    private function worktimePermission(array $parts, bool $read): string
    {
        if ($read) return 'worktime.view';
        if (in_array('calendar', $parts, true)) return 'worktime.calendar.edit';
        return 'worktime.write';
    }

    private function leavePermission(array $parts, bool $read): string
    {
        if ($read) return 'leaves.view';
        if ($parts[0] === 'leave-types') return 'leave.types.manage';
        if (in_array('manager-decision', $parts, true)) return 'leave.requests.review';
        if (in_array('decision', $parts, true) || in_array('reverse-approved', $parts, true)
            || in_array('actual', $parts, true) || in_array('review', $parts, true)) {
            return 'leave.requests.approve';
        }
        return 'leave.requests.manage';
    }
}

<?php

namespace App\Domain\Personnel;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Shared server authorization for every private employee mutation.
 * Connected Vue capability flags are never accepted as proof of access.
 */
final class EmployeePrivateWriteAuthorization
{
    public function __construct(private readonly EmployeeVisibility $visibility) {}

    public function canWrite(Request $request): bool
    {
        abort_unless((bool) config('hrm.personnel_writes_enabled', false), 404);

        $company = (string) $request->route('company');
        $employee = (string) $request->route('employee');
        $role = DB::table('company_user_roles')
            ->where('company_id', $company)
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->value('role_code');

        if (! in_array($role, ['company_admin', 'hr_specialist'], true)) {
            return false;
        }

        $this->visibility->visibleFor($request->user(), $company)
            ->where('employees.id', $employee)->firstOrFail();

        return true;
    }
}

<?php

namespace App\Domain\Identity;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AccountLifecycle
{
    public function changeState(User $target, AccountState $wanted): bool
    {
        return DB::transaction(function () use ($target, $wanted): bool {
            /** @var User|null $locked */
            $locked = User::query()->whereKey($target->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                abort(404);
            }

            if ($locked->account_status === $wanted) {
                return false;
            }

            if ($wanted->blocksAccess()) {
                $this->assertNotLastCompanyAdmin($locked);
            }

            $locked->forceFill(['account_status' => $wanted->value])->save();

            // Database-backed sessions are revoked across application instances.
            // Other session drivers still fail closed on their next request because
            // EnsureActiveAccount re-reads the current user state.
            if (DB::getSchemaBuilder()->hasTable('sessions')) {
                DB::table('sessions')->where('user_id', $locked->getKey())->delete();
            }

            return true;
        }, 3);
    }

    private function assertNotLastCompanyAdmin(User $target): void
    {
        $companyIds = DB::table('company_user_roles')
            ->where('user_id', $target->getKey())
            ->where('role_code', CompanyRole::CompanyAdmin->value)
            ->pluck('company_id');

        foreach ($companyIds as $companyId) {
            $otherAdmins = DB::table('company_user_roles')
                ->where('company_id', $companyId)
                ->where('role_code', CompanyRole::CompanyAdmin->value)
                ->where('user_id', '<>', $target->getKey())
                ->whereExists(function ($query): void {
                    $query->selectRaw('1')
                        ->from('users')
                        ->whereColumn('users.id', 'company_user_roles.user_id')
                        ->where('users.account_status', AccountState::Active->value);
                })
                ->exists();

            if (!$otherAdmins) {
                throw ValidationException::withMessages([
                    'account_status' => 'Cannot deactivate the last active company administrator.',
                ]);
            }
        }
    }
}

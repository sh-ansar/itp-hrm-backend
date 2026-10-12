<?php

namespace App\Console\Commands;

use App\Domain\Identity\AccountLifecycle;
use App\Domain\Identity\AccountState;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

final class ChangeAccountState extends Command
{
    protected $signature = 'hrm:account-status {user : Numeric user id} {status : invited|active|suspended|disabled}';

    protected $description = 'Change an HRM account state and revoke database sessions when access is blocked';

    public function handle(AccountLifecycle $lifecycle): int
    {
        $userId = filter_var($this->argument('user'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $state = AccountState::tryFrom((string) $this->argument('status'));

        if ($userId === false || $state === null) {
            $this->error('Valid numeric user id and account state are required.');

            return self::INVALID;
        }

        $user = User::query()->find($userId);

        if ($user === null) {
            $this->error('User not found.');

            return self::FAILURE;
        }

        try {
            $changed = $lifecycle->changeState($user, $state);
        } catch (ValidationException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info($changed ? 'Account state updated.' : 'Account state already matches.');

        return self::SUCCESS;
    }
}

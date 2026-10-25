<?php

namespace App\Jobs;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;

/** Laravel broker tokens remain one-time; queue storage never contains plaintext tokens. */
final class QueuedResetPassword extends ResetPassword implements ShouldQueue, ShouldBeEncrypted
{
    use Queueable;
    public int $tries = 5;
    public int $timeout = 60;
    public function __construct(string $token)
    {
        parent::__construct($token);
        $this->onQueue('hrm-notifications')->afterCommit();
    }
    public function shouldSend(object $notifiable, string $channel): bool
    {
        return $notifiable->account_status?->value === 'active'
            && \Illuminate\Support\Facades\Password::broker()->tokenExists($notifiable, $this->token);
    }
    public function backoff(): array { return [30, 120, 300, 600]; }
}

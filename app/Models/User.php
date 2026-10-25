<?php

namespace App\Models;

use App\Domain\Identity\AccountState;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    public function sendPasswordResetNotification($token): void
    {
        if (config('hrm.workflow_notifications_enabled', false)) {
            if (in_array(config('queue.default'), ['sync', 'null'], true)) {
                throw new \RuntimeException('HRM reset mail requires a persistent queue.');
            }
            $this->notify(new \App\Jobs\QueuedResetPassword($token));
            return;
        }
        $this->notify(new \Illuminate\Auth\Notifications\ResetPassword($token));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'account_status' => AccountState::class,
        ];
    }
}

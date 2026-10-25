<?php

namespace App\Jobs;

use App\Domain\Notifications\WorkflowOutbox;
use App\Mail\WorkflowUpdateMail;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

final class SendWorkflowNotification implements ShouldQueue
{
    use Queueable;
    public int $tries = 8;
    public int $timeout = 60;
    public function __construct(public string $notificationId) {}
    public function backoff(): array { return [30, 120, 600, 1800, 3600]; }

    public function handle(WorkflowOutbox $outbox): void
    {
        if (!config('hrm.workflow_notifications_enabled', false)) return;
        $lease = (string) Str::uuid();
        $item = DB::transaction(function () use ($lease, $outbox): ?object {
            $row = DB::table('hrm_notifications')->where('id', $this->notificationId)->lockForUpdate()->first();
            if (!$row || in_array($row->mail_state, ['accepted', 'failed', 'cancelled'], true)
                || \Carbon\CarbonImmutable::parse($row->available_at)->isFuture()
                || ($row->mail_state === 'sending' && $row->locked_until && \Carbon\CarbonImmutable::parse($row->locked_until)->isFuture())) return null;
            if ((int) $row->attempts >= 8) {
                DB::table('hrm_notifications')->where('id', $row->id)->update([
                    'mail_state' => 'failed', 'last_error_code' => 'WorkerLeaseExpired',
                    'locked_until' => null, 'lease_token' => null, 'updated_at' => now(),
                ]);
                DB::table('hrm_notification_deliveries')->insertOrIgnore([
                    'notification_id' => $row->id, 'attempt' => $row->attempts,
                    'result' => 'worker_lease_expired', 'error_code' => 'WorkerLeaseExpired', 'created_at' => now(),
                ]);
                return null;
            }
            $user = User::find($row->user_id);
            $pref = $outbox->preferences((int) $row->user_id);
            if (!$user || !$outbox->stillAuthorized($row, $user) || !($pref['channels']['email'] ?? true)
                || !($pref['rules'][$row->rule] ?? true)) {
                DB::table('hrm_notifications')->where('id', $row->id)->update(['mail_state' => 'cancelled', 'updated_at' => now()]);
                return null;
            }
            DB::table('hrm_notifications')->where('id', $row->id)->update([
                'mail_state' => 'sending', 'lease_token' => $lease, 'locked_until' => now()->addMinutes(5),
                'attempts' => (int) $row->attempts + 1, 'updated_at' => now(),
            ]);
            $row->attempts = (int) $row->attempts + 1;
            $row->recipient = $user->email;
            return $row;
        }, 3);
        if (!$item) return;
        // No transaction / row lock is held across SMTP. A stable Message-ID
        // mitigates provider duplicates after worker death; SMTP is at-least-once.
        $failure = null;
        try {
            Mail::to($item->recipient)->send(new WorkflowUpdateMail($item->id, $item->correlation_id, $item->locale));
        } catch (\Throwable $error) {
            $failure = class_basename($error);
        }
        DB::transaction(function () use ($item, $lease, $failure): void {
            $changed = DB::table('hrm_notifications')->where('id', $item->id)->where('lease_token', $lease)
                ->where('mail_state', 'sending')->update([
                    'mail_state' => $failure ? ($item->attempts >= 8 ? 'failed' : 'pending') : 'accepted',
                    'available_at' => now()->addSeconds($this->backoff()[min($item->attempts - 1, 4)]),
                    'accepted_at' => $failure ? null : now(), 'last_error_code' => $failure,
                    'lease_token' => null, 'locked_until' => null, 'updated_at' => now(),
                ]);
            if ($changed) DB::table('hrm_notification_deliveries')->insert([
                'notification_id' => $item->id, 'attempt' => $item->attempts,
                'result' => $failure ? 'transport_failed' : 'transport_accepted',
                'error_code' => $failure, 'created_at' => now(),
            ]);
        }, 3);
        if ($failure) throw new \RuntimeException('HRM mail transport failed; correlation '.$item->correlation_id);
    }
}

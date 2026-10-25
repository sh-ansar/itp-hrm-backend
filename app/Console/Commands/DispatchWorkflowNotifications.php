<?php

namespace App\Console\Commands;

use App\Jobs\SendWorkflowNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class DispatchWorkflowNotifications extends Command
{
    protected $signature = 'hrm:notifications:dispatch {--limit=100}';
    protected $description = 'Queue due HRM outbox deliveries without sending mail in HTTP transactions';
    public function handle(): int
    {
        if (!config('hrm.workflow_notifications_enabled', false)) return self::SUCCESS;
        if (in_array(config('queue.default'), ['sync', 'null'], true)) {
            $this->error('Use a persistent queue connection for workflow notifications.');
            return self::FAILURE;
        }
        if (app()->environment('production') && config('mail.default') !== 'smtp') {
            $this->error('Production workflow mail requires the verified SMTP transport.');
            return self::FAILURE;
        }
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        if ($limit === false) return self::INVALID;
        $ids = DB::table('hrm_notifications')->where('attempts', '<=', 8)->where('available_at', '<=', now())
            ->where(fn ($q) => $q->where('mail_state', 'pending')->orWhere(fn ($s) =>
                $s->where('mail_state', 'sending')->where('locked_until', '<=', now())))
            ->orderBy('available_at')->limit($limit)->pluck('id');
        foreach ($ids as $id) SendWorkflowNotification::dispatch($id)->onQueue('hrm-notifications');
        $this->info('Queued '.$ids->count().' due HRM notifications.');
        return self::SUCCESS;
    }
}

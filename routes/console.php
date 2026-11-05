<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

\Illuminate\Support\Facades\Schedule::command('hrm:notifications:dispatch')->everyMinute()->withoutOverlapping();


// Manual, verified-user bootstrap. Never auto-grant commercial administrator rights.
\Illuminate\Support\Facades\Artisan::command(
    'hrm:platform-grant {email} {permission=platform.manage}',
    function (): void {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $permission = (string) $this->argument('permission');
        if (!in_array($permission, \App\Services\HrmPlatformAccess::PERMISSIONS, true)) {
            throw new \InvalidArgumentException('Unknown platform permission');
        }
        $user = \Illuminate\Support\Facades\DB::table('users')->where('email', $email)->first();
        if (!$user || $user->account_status !== 'active' || !$user->email_verified_at) {
            throw new \RuntimeException('Verified active account required');
        }
        \Illuminate\Support\Facades\DB::table('hrm_platform_admin_grants')->insertOrIgnore([
            'user_id' => $user->id, 'permission' => $permission,
            'granted_by' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        \App\Services\HrmPlatformAccess::audit(
            (int) $user->id, null, 'platform.bootstrap', 'user:'.$user->id, ['permission' => $permission]
        );
        $this->info('Grant recorded');
    }
)->purpose('Grant HRM platform SaaS permission to a verified active user');


/**
 * Copy the commercially reviewed Portal reference into HRM draft records.
 * This NEVER publishes tariffs, publishes legal terms, or enables paid limits.
 * Run only after a platform admin has explicitly authorized the migration.
 */
\Illuminate\Support\Facades\Artisan::command(
    'hrm:commercial-prepare {email}',
    function (): void {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $user = \Illuminate\Support\Facades\DB::table('users')->where('email', $email)->first();
        if (!$user || !$user->email_verified_at || $user->account_status !== 'active') {
            throw new \RuntimeException('Verified active account required');
        }
        if (!\App\Services\HrmPlatformAccess::allows((int) $user->id, 'platform.manage')) {
            throw new \RuntimeException('Platform administrator permission required');
        }
        $result = \App\Services\HrmCommercialBootstrap::prepare((int) $user->id);
        foreach ($result as $name => $number) $this->info($name.': '.$number);
        $this->warn('All plans and legal texts remain DRAFT until explicit approval.');
    }
)->purpose('Prepare adapted HRM commercial plans and legal document drafts (never publish)');

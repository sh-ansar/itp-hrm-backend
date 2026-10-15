<?php

namespace App\Console\Commands;

use App\Domain\Identity\AccountState;
use App\Domain\Identity\CompanyRole;
use App\Models\User;
use Database\Seeders\ItpCompaniesSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use RuntimeException;

/**
 * CLI-only, one-time privileged bootstrap for the isolated HRM database.
 *
 * Password is read from a temporary 0600-equivalent Windows ACL-protected file.
 * It is NEVER accepted as an argument, logged, emailed or committed.
 */
final class BootstrapItpAdmin extends Command
{
    protected $signature = 'hrm:bootstrap-itp-admin
        {email : Administrator email}
        {--name=ITP HRM Administrator : Display name}
        {--password-file= : Absolute path to a private one-time password file}';

    protected $description = 'Create an HRM-only company administrator for three linked ITP companies';

    public function handle(): int
    {
        if (!(bool) config('hrm.admin_bootstrap_enabled', false)) {
            throw new RuntimeException('Administrator bootstrap is disabled');
        }

        if (!app()->environment('testing')) {
            if (config('database.default') !== 'pgsql'
                || config('database.connections.pgsql.host') !== '127.0.0.1'
                || (string) config('database.connections.pgsql.port') !== '55432'
                || config('database.connections.pgsql.database') !== 'hrm'
                || config('database.connections.pgsql.search_path') !== 'hrm') {
                throw new RuntimeException('Refusing administrator creation outside HRM');
            }
            $server = DB::selectOne('SELECT current_database() AS db, current_schema() AS schema_name, inet_server_port() AS port');
            if (!$server || $server->db !== 'hrm' || $server->schema_name !== 'hrm'
                || (int) $server->port !== 55432) {
                throw new RuntimeException('HRM database identity did not match');
            }
        }

        $email = mb_strtolower(trim((string) $this->argument('email')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
            throw new RuntimeException('Invalid administrator email');
        }

        // Keep absolute paths in a dedicated directory; never allow input to
        // select arbitrary local application or platform configuration files.
        $file = (string) $this->option('password-file');
        $resolved = $file !== '' ? realpath($file) : false;
        if (!$resolved || !is_file($resolved)) {
            throw new RuntimeException('Password file not found');
        }
        $privateRoot = realpath('C:/deploy/hrm/private-secrets');
        if (!app()->environment('testing') && (!$privateRoot
            || dirname($resolved) !== $privateRoot)) {
            throw new RuntimeException('Password file must belong to the private HRM secret directory');
        }

        $password = rtrim((string) file_get_contents($resolved), "\r\n");
        $check = Validator::make(['password' => $password], [
            'password' => ['required', Password::min(12)->mixedCase()->numbers()],
        ]);
        if ($check->fails()) {
            throw new RuntimeException('Administrator password must meet the strong-password policy');
        }

        $sources = DB::table('company_external_references')
            ->where('source_system', ItpCompaniesSeeder::SOURCE)
            ->whereIn('source_id', ['1', '2', '3'])
            ->orderBy('source_id')->get(['source_id', 'company_id']);
        if ($sources->count() !== 3 || $sources->pluck('company_id')->unique()->count() !== 3) {
            throw new RuntimeException('Bootstrap ITP Mining, ITP Georgia and ITP Solutions first');
        }

        DB::transaction(function () use ($email, $password, $sources): void {
            if (User::query()->where('email', $email)->exists()) {
                throw new RuntimeException('Account already exists: refusing reset or privilege escalation');
            }

            $user = User::query()->create([
                'name' => (string) $this->option('name'),
                'email' => $email,
                'password' => $password, // Eloquent hashed cast
            ]);
            $user->forceFill(['account_status' => AccountState::Active->value])->save();

            foreach ($sources as $company) {
                DB::table('company_user_roles')->insert([
                    'company_id' => $company->company_id,
                    'user_id' => $user->getKey(),
                    'role_code' => CompanyRole::CompanyAdmin->value,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }, 3);

        $password = '';
        $this->components->info('One HRM administrator created with three company memberships.');
        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Domain\Demo\DemoPersonnelCatalogue;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class PopulateApprovedDemo extends Command
{
    protected $signature = 'hrm:demo-personnel {--actor= : Existing company administrator email}
        {--target= : Explicit approved target token}
        {--backup-manifest= : Verified restore receipt in the private HRM backup directory}
        {--apply : Apply the bounded catalogue; omitted means read-only preflight}';
    protected $description = 'Fill existing ITP Mining/Solutions to 80/20 active staff without overwriting existing people';

    public function handle(): int
    {
        if ($this->option('target') !== 'hrm-demo-20261027') throw new RuntimeException('An exact approved target is required.');
        if (config('database.default') !== 'pgsql'
            || config('database.connections.pgsql.host') !== '127.0.0.1'
            || (string) config('database.connections.pgsql.port') !== '55432'
            || config('database.connections.pgsql.database') !== 'hrm'
            || config('database.connections.pgsql.search_path') !== 'hrm') {
            throw new RuntimeException('Only the isolated HRM database is permitted.');
        }
        $identity = DB::selectOne('SELECT current_database() AS db,current_schema() AS schema_name,current_user AS actor,inet_server_port() AS port');
        if ($identity->db !== 'hrm' || $identity->schema_name !== 'hrm' || (int) $identity->port !== 55432
            || !in_array($identity->actor, ['hrm_app', 'hrm_migrator'], true)) throw new RuntimeException('Actual HRM identity mismatch.');
        $actor = User::query()->where('email', $this->option('actor'))->firstOrFail();
        if (!$this->option('apply')) {
            $this->line(json_encode(['database' => 'hrm', 'mode' => 'preflight', 'targets' => DemoPersonnelCatalogue::TARGETS], JSON_THROW_ON_ERROR));
            return self::SUCCESS;
        }
        if (!(bool) config('hrm.personnel_writes_enabled') || !(bool) config('hrm.worktime_writes_enabled')) {
            throw new RuntimeException('Existing application write permissions must be enabled; command does not bypass them.');
        }
        if ((bool) config('hrm.workflow_notifications_enabled')) throw new RuntimeException('Disable workflow mail for bulk demo creation; no mass mail is permitted.');
        $root = realpath('C:/deploy/hrm/private-secrets/demo-backup-20261027');
        $manifest = realpath((string) $this->option('backup-manifest'));
        if (!$root || !$manifest || dirname($manifest) !== $root) throw new RuntimeException('Verified HRM backup receipt is required.');
        $receipt = json_decode(preg_replace('/^\\xEF\\xBB\\xBF/', '', file_get_contents($manifest)), true, 512, JSON_THROW_ON_ERROR);
        $backup = $root.DIRECTORY_SEPARATOR.'hrm-before-demo.dump';
        if (($receipt['database'] ?? null) !== 'hrm' || ($receipt['restored'] ?? false) !== true
            || ($receipt['rehearsalDatabase'] ?? null) !== 'hrm_demo_rehearsal_20261027'
            || !is_file($backup) || !hash_equals((string) ($receipt['backupSHA256'] ?? ''), strtoupper(hash_file('sha256', $backup)))
            || !isset($receipt['createdUtc']) || strtotime($receipt['createdUtc']) < time() - 86400) {
            throw new RuntimeException('Recent verified restore receipt and matching dump are required.');
        }
        $this->line(json_encode(app(DemoPersonnelCatalogue::class)->apply($actor), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        return self::SUCCESS;
    }
}

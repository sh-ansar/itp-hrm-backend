<?php

declare(strict_types=1);

// Disposable PostgreSQL 16 upgrade rehearsal only. Never run on an HRM service
// database, even if the caller accidentally supplies APP_ENV=testing.
$stage = $argv[1] ?? '';
if (!in_array($stage, ['seed', 'verify'], true)
    || PHP_SAPI !== 'cli'
    || getenv('CI') !== 'true'
    || getenv('GITHUB_ACTIONS') !== 'true'
    || getenv('HRM_DISPOSABLE_UPGRADE') !== 'pgsql16-rehearsal') {
    throw new RuntimeException('HRM release fixture requires the disposable GitHub PostgreSQL upgrade runner.');
}

$repoRoot = dirname(__DIR__, 2);
$appRoot = $stage === 'seed' ? $repoRoot.'/old-backend' : $repoRoot;
if (!is_file($appRoot.'/vendor/autoload.php')
    || !is_file($appRoot.'/bootstrap/app.php')) {
    throw new RuntimeException('Expected reviewed backend source checkout is missing.');
}
require $appRoot.'/vendor/autoload.php';
$app = require $appRoot.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if (!app()->environment('testing')
    || config('database.default') !== 'pgsql'
    || (string) config('database.connections.pgsql.host') !== '127.0.0.1'
    || (string) config('database.connections.pgsql.port') !== '5432'
    || (string) config('database.connections.pgsql.database') !== 'itp_hrm_upgrade_ci'
    || (string) config('database.connections.pgsql.username') !== 'hrm_upgrade_ci'
    || (string) config('database.connections.pgsql.search_path') !== 'public') {
    throw new RuntimeException('Connected database configuration is not the disposable release test.');
}

// Assert server identity before even inserting the synthetic legacy record.
$server = \Illuminate\Support\Facades\DB::selectOne(
    'select current_database() as database_name, current_user as user_name, '.
    'inet_server_addr()::text as address, inet_server_port() as port'
);
if ($server === null
    || $server->database_name !== 'itp_hrm_upgrade_ci'
    || $server->user_name !== 'hrm_upgrade_ci'
    || $server->address !== '127.0.0.1'
    || (int) $server->port !== 5432) {
    throw new RuntimeException('Connected PostgreSQL server is not the disposable CI instance.');
}

$companyId = '11111111-2222-4333-8444-555555555555';
$employeeId = '22222222-3333-4444-8555-666666666666';
$documentId = '33333333-4444-4555-8666-777777777777';
$db = \Illuminate\Support\Facades\DB::class;

if ($stage === 'seed') {
    if (\Illuminate\Support\Facades\Schema::hasColumn('personnel_documents', 'employee_visible')
        || \Illuminate\Support\Facades\Schema::hasTable('employee_user_links')) {
        throw new RuntimeException('Baseline schema unexpectedly contains new-release tables or columns.');
    }
    \Illuminate\Support\Facades\DB::transaction(static function () use ($companyId, $employeeId, $documentId): void {
        $now = now();
        \Illuminate\Support\Facades\DB::table('companies')->insert([
            'id' => $companyId, 'name' => 'Synthetic CI upgrade company',
        ]);
        \Illuminate\Support\Facades\DB::table('employees')->insert([
            'id' => $employeeId,
            'company_id' => $companyId,
            'personnel_number' => 'UPGRADE-CI-001',
            'first_name' => 'Legacy',
            'last_name' => 'Synthetic',
        ]);
        \Illuminate\Support\Facades\DB::table('personnel_documents')->insert([
            'id' => $documentId,
            'company_id' => $companyId,
            'employee_id' => $employeeId,
            'document_type' => 'order',
            'document_number' => 'CI-LEGACY-001',
            'title' => 'Synthetic historic order',
            'issued_on' => '2026-10-16',
            'status' => 'draft',
            'revision' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    });
    echo "Synthetic historic HRM records inserted into isolated baseline schema.\n";
    exit(0);
}

// The new migrations MUST NOT retroactively publish, approve, or attribute
// old private documents to a made-up HR actor.
$employee = \Illuminate\Support\Facades\DB::table('employees')->where('id', $employeeId)->first();
$document = \Illuminate\Support\Facades\DB::table('personnel_documents')->where('id', $documentId)->first();
if (!$employee || !$document
    || $employee->company_id !== $companyId
    || $employee->personnel_number !== 'UPGRADE-CI-001'
    || $employee->last_name !== 'Synthetic'
    || $document->employee_id !== $employeeId
    || $document->status !== 'draft'
    || (int) $document->revision !== 1
    || $document->document_number !== 'CI-LEGACY-001'
    || (bool) $document->employee_visible !== false
    || $document->created_by_user_id !== null
    || $document->submitted_by_user_id !== null
    || $document->reviewed_by_user_id !== null
    || $document->review_note !== null) {
    throw new RuntimeException('Existing employee/order data was changed or exposed by schema upgrade.');
}

foreach ([
    'employee_user_links', 'leave_balance_periods', 'leave_balance_entries',
    'leave_request_evidence',
] as $table) {
    if (!\Illuminate\Support\Facades\Schema::hasTable($table)) {
        throw new RuntimeException("Required new relation {$table} was not migrated.");
    }
}
$expected = [
    '2026_10_16_230000_create_employee_user_links',
    '2026_10_19_000001_add_employee_visibility_to_personnel_documents',
    '2026_10_19_000002_create_leave_balance_periods',
    '2026_10_19_000003_create_leave_request_evidence',
    '2026_10_19_000004_add_order_approval_to_personnel_documents',
];
$applied = \Illuminate\Support\Facades\DB::table('migrations')
    ->whereIn('migration', $expected)->pluck('migration')->all();
if (count($applied) !== count($expected)) {
    throw new RuntimeException('The release contains unexecuted or unregistered migrations.');
}
echo "Existing employee and historic private draft preserved; five additive migrations verified.\n";

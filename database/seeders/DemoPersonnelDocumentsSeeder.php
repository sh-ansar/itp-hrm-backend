<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Optional synthetic C02 preview only.
 *
 * Never called by DatabaseSeeder and refuses any database except the isolated
 * itp_hrm_demo PostgreSQL database in APP_ENV=local.
 */
final class DemoPersonnelDocumentsSeeder extends Seeder
{
    public function run(): void
    {
        if (config('app.env') !== 'local' || DB::connection()->getDatabaseName() !== 'itp_hrm_demo') {
            throw new RuntimeException('Demo personnel documents may only be seeded into local itp_hrm_demo.');
        }

        $company = DB::table('companies')->where('name', 'ITP Demo Enterprise')->first(['id']);
        if ($company === null) {
            throw new RuntimeException('Expected synthetic demo company was not found.');
        }

        $employees = DB::table('employees')
            ->where('company_id', $company->id)
            ->where('personnel_number', 'like', 'DEMO-%')
            ->orderBy('personnel_number')
            ->limit(12)
            ->get(['id', 'personnel_number']);
        if ($employees->count() !== 12) {
            throw new RuntimeException('Expected at least 12 synthetic DEMO employees; refusing to seed.');
        }

        $types = ['order', 'contract', 'basis', 'other'];
        $titles = [
            'DEMO · Приказ о кадровом назначении',
            'DEMO · Черновик трудового договора',
            'DEMO · Основание кадрового решения',
            'DEMO · Материал личного дела',
        ];
        $now = now();

        DB::transaction(static function () use ($company, $employees, $types, $titles, $now): void {
            foreach ($employees as $index => $employee) {
                $number = sprintf('DEMO-DOC-%04d', $index + 1);
                if (DB::table('personnel_documents')->where('company_id', $company->id)
                    ->where('document_number', $number)->exists()) {
                    continue;
                }

                $typeIndex = $index % count($types);
                $actionId = $typeIndex === 0
                    ? DB::table('personnel_actions')->where('company_id', $company->id)
                        ->where('employee_id', $employee->id)
                        ->orderBy('effective_on')->value('id')
                    : null;

                DB::table('personnel_documents')->insert([
                    'id' => (string) Str::uuid(),
                    'company_id' => $company->id,
                    'employee_id' => $employee->id,
                    'personnel_action_id' => $actionId,
                    'document_type' => $types[$typeIndex],
                    'document_number' => $number,
                    'title' => $titles[$typeIndex],
                    'issued_on' => sprintf('2026-%02d-%02d', 1 + ($index % 9), 5 + ($index % 18)),
                    'status' => 'draft',
                    'revision' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });
    }
}

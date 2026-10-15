<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Snapshot from the ITP Platform companies directory, read-only verified
 * on 2026-10-15. This seeder writes ONLY to the independent HRM database.
 * It must be invoked explicitly, never from DatabaseSeeder.
 */
final class ItpCompaniesSeeder extends Seeder
{
    public const SOURCE = 'itp_platform';

    private const ROWS = [
        [
            'source_id' => '1',
            'name' => 'ITP Mining',
            'source_name' => 'ITP Mining',
            'legal_address' => 'Bauyrzhan Momyshuly Avenue, 12a, 305',
            'physical_address' => 'Bauyrzhan Momyshuly Avenue, 12a, 305',
            'phone' => '+7773337777',
        ],
        [
            'source_id' => '2',
            'name' => 'ITP Georgia',
            'source_name' => 'ITP Georgia',
            'legal_address' => 'Georgia, Tbilisi City, Didube District, Samtredia Street N4a, Flat 19',
            'physical_address' => 'Georgia, Tbilisi City, Didube District, Samtredia Street N4a, Flat 19',
            'phone' => '+995 595 09 63 50',
        ],
        [
            'source_id' => '3',
            'name' => 'ITP Solutions',
            'source_name' => 'ITP Solution',
            'legal_address' => 'Республика Казахстан, г. Астана, пр. Б. Момышулы, дом № 12/А, оф. 305 (Секция «В»)',
            'physical_address' => 'Республика Казахстан, г. Астана, пр. Б. Момышулы, дом № 12/А, оф. 305 (Секция «В»)',
            'phone' => '+7 (747) 467 0526',
        ],
    ];

    public function run(): void
    {
        if (!(bool) config('hrm.company_bootstrap_enabled', false)) {
            throw new RuntimeException('ITP company bootstrap is disabled');
        }

        if (!app()->environment('testing')) {
            if (config('database.default') !== 'pgsql'
                || config('database.connections.pgsql.host') !== '127.0.0.1'
                || (string) config('database.connections.pgsql.port') !== '55432'
                || config('database.connections.pgsql.database') !== 'hrm'
                || config('database.connections.pgsql.search_path') !== 'hrm') {
                throw new RuntimeException('Refusing bootstrap outside the isolated HRM database');
            }
            $identity = DB::selectOne("SELECT current_database() AS db, current_schema() AS schema_name, inet_server_port() AS port");
            if (!$identity || $identity->db !== 'hrm' || $identity->schema_name !== 'hrm'
                || (int) $identity->port !== 55432) {
                throw new RuntimeException('Database identity mismatch: no records created');
            }
        }

        DB::transaction(function (): void {
            foreach (self::ROWS as $entry) {
                $ref = DB::table('company_external_references')
                    ->where('source_system', self::SOURCE)
                    ->where('source_id', $entry['source_id'])->lockForUpdate()->first();
                $company = DB::table('companies')->where('name', $entry['name'])->lockForUpdate()->first();

                if ($ref && $company && $ref->company_id !== $company->id) {
                    throw new RuntimeException('Company/source mapping conflict for '.$entry['name']);
                }

                $id = $ref?->company_id ?? $company?->id ?? (string) Str::uuid();
                if ($ref) {
                    $mapped = DB::table('companies')->where('id', $id)->first(['id', 'name']);
                    if (!$mapped || $mapped->name !== $entry['name']) {
                        throw new RuntimeException('Referenced company is absent or was renamed');
                    }
                }

                if (!$company && !$ref) {
                    DB::table('companies')->insert([
                        'id' => $id, 'name' => $entry['name'],
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }

                $existing = DB::table('company_external_references')
                    ->where('source_system', self::SOURCE)
                    ->where('source_id', $entry['source_id'])->exists();

                if (!$existing) {
                    DB::table('company_external_references')->insert([
                        'company_id' => $id,
                        'source_system' => self::SOURCE,
                        'source_id' => $entry['source_id'],
                        'source_name' => $entry['source_name'],
                        'legal_address' => $entry['legal_address'],
                        'physical_address' => $entry['physical_address'],
                        'phone' => $entry['phone'],
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                } else {
                    DB::table('company_external_references')
                        ->where('source_system', self::SOURCE)
                        ->where('source_id', $entry['source_id'])
                        ->update([
                            'source_name' => $entry['source_name'],
                            'legal_address' => $entry['legal_address'],
                            'physical_address' => $entry['physical_address'],
                            'phone' => $entry['phone'],
                            'updated_at' => now(),
                        ]);
                }
            }
        }, 3);
    }
}

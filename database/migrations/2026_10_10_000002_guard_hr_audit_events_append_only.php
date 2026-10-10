<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('hr_audit_events')) {
            throw new RuntimeException('HR audit events must exist before installing append-only guards.');
        }

        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION hrm_reject_audit_mutation() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'hr_audit_events is append-only';
                END;
                $$ LANGUAGE plpgsql
            SQL);

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER hrm_audit_no_update_or_delete
                BEFORE UPDATE OR DELETE ON hr_audit_events
                FOR EACH ROW EXECUTE FUNCTION hrm_reject_audit_mutation()
            SQL);

            // DELETE triggers do not protect TRUNCATE in PostgreSQL.
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER hrm_audit_no_truncate
                BEFORE TRUNCATE ON hr_audit_events
                FOR EACH STATEMENT EXECUTE FUNCTION hrm_reject_audit_mutation()
            SQL);

            return;
        }

        if ($driver === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER hrm_audit_no_update
                BEFORE UPDATE ON hr_audit_events
                BEGIN
                    SELECT RAISE(ABORT, 'hr_audit_events is append-only');
                END
            SQL);

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER hrm_audit_no_delete
                BEFORE DELETE ON hr_audit_events
                BEGIN
                    SELECT RAISE(ABORT, 'hr_audit_events is append-only');
                END
            SQL);

            return;
        }

        // Do not silently deploy without protection on an unsupported database.
        throw new RuntimeException("Append-only HR audit guards are unsupported on database driver: {$driver}");
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS hrm_audit_no_update_or_delete ON hr_audit_events');
            DB::unprepared('DROP TRIGGER IF EXISTS hrm_audit_no_truncate ON hr_audit_events');
            DB::unprepared('DROP FUNCTION IF EXISTS hrm_reject_audit_mutation()');

            return;
        }

        if ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS hrm_audit_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS hrm_audit_no_delete');

            return;
        }

        throw new RuntimeException("Cannot roll back HR audit guards on unsupported driver: {$driver}");
    }
};

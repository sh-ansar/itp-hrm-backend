<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['employee_experience', 'employee_reserve', 'employee_training'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, static function (Blueprint $t): void {
                // Compare-and-swap revision prevents an outdated editor overwriting a newer change.
                $t->unsignedInteger('revision')->default(1);
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, static function (Blueprint $t): void {
                $t->dropColumn('revision');
            });
        }
    }
};

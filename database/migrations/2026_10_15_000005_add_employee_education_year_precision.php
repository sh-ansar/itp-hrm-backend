<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The ZIP reference uses years (e.g. 2002–2006), not guessed Jan 1 dates.
 * Preserve optional precise dates independently for imported verifiable data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_education_records', function (Blueprint $table): void {
            $table->unsignedSmallInteger('started_year')->nullable();
            $table->unsignedSmallInteger('ended_year')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('employee_education_records', function (Blueprint $table): void {
            $table->dropColumn(['started_year', 'ended_year']);
        });
    }
};

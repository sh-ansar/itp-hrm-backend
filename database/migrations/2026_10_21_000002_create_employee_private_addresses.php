<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * B74: company-scoped, repeatable HR-only employee addresses.
 * Legacy employee_private_profiles residence/registration columns remain unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_private_addresses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('employee_id');
            $table->string('kind', 20); // residence, registration, other
            $table->string('address_line', 500);
            $table->string('entrance', 40)->nullable();
            $table->string('floor', 20)->nullable();
            $table->string('apartment', 40)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->unsignedInteger('revision')->default(1);
            $table->timestampTz('archived_at')->nullable();
            $table->timestamps();
            $table->foreign(['employee_id','company_id'], 'private_address_employee_tenant_fk')
                ->references(['id','company_id'])->on('employees')->restrictOnDelete();
            $table->index(['company_id','employee_id','archived_at'], 'private_address_by_employee_idx');
        });
        // This protects imports and direct SQL from creating two active primaries.
        DB::statement('CREATE UNIQUE INDEX private_address_one_primary ON employee_private_addresses (company_id, employee_id) WHERE is_primary AND archived_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_private_addresses');
    }
};

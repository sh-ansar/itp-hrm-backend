<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_contracts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('employee_id');
            $table->string('contract_number', 128);
            $table->string('contract_type', 32);
            $table->string('employment_type', 32)->nullable();
            $table->date('signed_on')->nullable();
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->date('probation_until')->nullable();
            $table->string('basis_reference', 255)->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['employee_id', 'company_id'], 'contract_employee_company_fk')
                ->references(['id', 'company_id'])->on('employees')->cascadeOnDelete();
            $table->unique(['company_id', 'contract_number'], 'contract_company_number_unique');
            $table->index(['company_id', 'valid_to'], 'contract_company_expiry_index');
            $table->index(['employee_id', 'valid_from', 'valid_to'], 'contract_employee_period_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_contracts');
    }
};

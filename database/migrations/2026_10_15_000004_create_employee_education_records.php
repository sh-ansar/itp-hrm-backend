<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Formal education/qualifications are not employee_training events.
 * Store only HR-entered facts; do not infer degree validity or national rules.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_education_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('employee_id');
            $table->string('education_type', 100);
            $table->string('organization', 255);
            $table->date('started_on')->nullable();
            $table->date('ended_on')->nullable();
            $table->string('specialization', 255)->nullable();
            // Neutral document reference. Private uploads belong to document storage.
            $table->string('document_reference', 255)->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestampTz('archived_at')->nullable();
            $table->timestamps();

            $table->foreign(['employee_id', 'company_id'], 'education_employee_tenant_fk')
                ->references(['id', 'company_id'])->on('employees')->restrictOnDelete();
            $table->index(['company_id', 'employee_id', 'archived_at'], 'education_employee_active');
            $table->index(['employee_id', 'ended_on'], 'education_employee_end');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_education_records');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Repeatable, private records from the source employee-card prototype. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_family_members', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('employee_id');
            $table->uuid('company_id');
            $table->string('relationship', 100);
            $table->string('full_name', 255);
            $table->date('birth_date')->nullable();
            // Registry reference only, not uploaded document contents.
            $table->string('document_reference', 255)->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestampTz('archived_at')->nullable();
            $table->timestamps();
            $table->foreign(['employee_id', 'company_id'], 'family_employee_tenant_fk')
                ->references(['id', 'company_id'])->on('employees')->restrictOnDelete();
            $table->index(['company_id', 'employee_id', 'archived_at'], 'family_active_by_employee');
        });

        Schema::create('employee_identity_documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('employee_id');
            $table->uuid('company_id');
            $table->string('type', 100);
            // Identifiers are encrypted with Laravel Crypt, not plain SQL columns.
            $table->text('number_ciphertext');
            $table->text('personal_number_ciphertext')->nullable();
            $table->string('country_of_issue', 100)->nullable();
            $table->string('issued_by', 255)->nullable();
            $table->date('issued_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->unsignedInteger('revision')->default(1);
            $table->timestampTz('archived_at')->nullable();
            $table->timestamps();
            $table->foreign(['employee_id', 'company_id'], 'identity_employee_tenant_fk')
                ->references(['id', 'company_id'])->on('employees')->restrictOnDelete();
            $table->index(['company_id', 'employee_id', 'archived_at'], 'identity_active_by_employee');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_identity_documents');
        Schema::dropIfExists('employee_family_members');
    }
};

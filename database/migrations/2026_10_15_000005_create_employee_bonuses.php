<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_bonuses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('employee_id');
            // A retryable request key is company-scoped, never a payroll transaction ID.
            $table->uuid('request_key');
            $table->string('bonus_kind', 32);
            $table->date('award_on');
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3);
            $table->string('basis_reference', 255);
            $table->text('note')->nullable();
            $table->string('status', 24)->default('draft');
            $table->text('decision_comment')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('submitted_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            // Requires leave_employee_tenant_reference from the existing leave migration.
            $table->foreign(['employee_id', 'company_id'], 'bonus_employee_company_fk')
                ->references(['id', 'company_id'])->on('employees')->restrictOnDelete();
            $table->unique(['company_id', 'request_key'], 'bonus_idempotent_company_key');
            $table->index(['company_id', 'status', 'award_on'], 'bonus_status_period_index');
            $table->index(['company_id', 'employee_id', 'award_on'], 'bonus_employee_period_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_bonuses');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Composite references prevent cross-company relation corruption at the DB level.
        Schema::table('employees', function (Blueprint $table): void {
            $table->unique(['id', 'company_id'], 'leave_employee_tenant_reference');
        });
        Schema::table('leave_types', function (Blueprint $table): void {
            $table->unique(['id', 'company_id'], 'leave_type_tenant_reference');
        });

        Schema::create('leave_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('employee_id');
            $table->uuid('leave_type_id');
            $table->date('planned_started_on');
            $table->date('planned_ended_on');
            $table->unsignedSmallInteger('planned_days');
            $table->string('status', 24)->default('draft');
            $table->text('note')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->uuid('published_leave_id')->nullable()->unique();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['employee_id', 'company_id'], 'leave_request_employee_tenant_fk')
                ->references(['id', 'company_id'])->on('employees')->restrictOnDelete();
            $table->foreign(['leave_type_id', 'company_id'], 'leave_request_type_tenant_fk')
                ->references(['id', 'company_id'])->on('leave_types')->restrictOnDelete();
            $table->foreign('published_leave_id')->references('id')->on('employee_leaves')->restrictOnDelete();
            $table->index(['company_id', 'status', 'planned_started_on'], 'leave_request_status_period');
            $table->index(['company_id', 'employee_id', 'planned_started_on'], 'leave_request_employee_period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_requests');
        Schema::table('leave_types', function (Blueprint $table): void {
            $table->dropUnique('leave_type_tenant_reference');
        });
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropUnique('leave_employee_tenant_reference');
        });
    }
};

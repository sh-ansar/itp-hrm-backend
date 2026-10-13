<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('leave_types', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('code', 64);
            $table->string('name', 255);
            $table->unsignedSmallInteger('annual_days')->nullable();
            $table->string('balance_method', 48)->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'name']);
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
        });

        Schema::create('employee_leaves', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('employee_id');
            $table->uuid('leave_type_id');
            $table->date('planned_started_on');
            $table->date('planned_ended_on');
            $table->unsignedSmallInteger('planned_days');
            $table->date('actual_started_on')->nullable();
            $table->date('actual_ended_on')->nullable();
            $table->unsignedSmallInteger('actual_days')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();

            $table->index(['employee_id', 'planned_started_on', 'planned_ended_on']);
            $table->index(['leave_type_id', 'planned_started_on']);
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
            $table->foreign('leave_type_id')->references('id')->on('leave_types')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_leaves');
        Schema::dropIfExists('leave_types');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('work_schedules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('code', 64);
            $table->string('name', 255);
            $table->string('schedule_type', 32);
            $table->string('work_time_label', 128)->nullable();
            $table->unsignedSmallInteger('weekly_minutes')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'name']);
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
        });

        Schema::create('employee_work_schedules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('employee_id');
            $table->uuid('work_schedule_id');
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();

            $table->index(['employee_id', 'valid_from', 'valid_to']);
            $table->index(['work_schedule_id', 'valid_from', 'valid_to']);
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
            $table->foreign('work_schedule_id')->references('id')->on('work_schedules')->cascadeOnDelete();
        });

        Schema::create('production_calendar_days', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->date('work_date');
            $table->string('day_type', 24);
            $table->unsignedSmallInteger('planned_minutes')->nullable();
            $table->string('label', 255)->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();

            $table->unique(['company_id', 'work_date']);
            $table->index(['company_id', 'day_type']);
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
        });

        Schema::create('worktime_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('employee_id');
            $table->date('work_date');
            $table->unsignedSmallInteger('planned_minutes')->nullable();
            $table->unsignedSmallInteger('worked_minutes')->nullable();
            $table->unsignedSmallInteger('absence_minutes')->nullable();
            $table->unsignedSmallInteger('overtime_minutes')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();

            $table->unique(['employee_id', 'work_date']);
            $table->index('work_date');
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
        });

        Schema::create('employee_absences', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('employee_id');
            $table->string('absence_type', 48);
            $table->string('reason', 255);
            $table->date('started_on');
            $table->date('ended_on');
            $table->unsignedInteger('absence_minutes')->nullable();
            $table->string('status', 24)->default('recorded');
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();

            $table->index(['employee_id', 'started_on', 'ended_on']);
            $table->index(['status', 'started_on']);
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_absences');
        Schema::dropIfExists('worktime_entries');
        Schema::dropIfExists('production_calendar_days');
        Schema::dropIfExists('employee_work_schedules');
        Schema::dropIfExists('work_schedules');
    }
};

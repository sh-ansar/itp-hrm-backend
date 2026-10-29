<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Immutable pattern snapshots: an edited template cannot rewrite an approved history.
        Schema::create('work_schedule_patterns', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('work_schedule_id');
            $table->unsignedInteger('version');
            $table->string('calendar_policy', 24); // company or cycle
            $table->json('days');
            $table->timestamps();
            $table->unique(['work_schedule_id', 'version']);
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('work_schedule_id')->references('id')->on('work_schedules')->restrictOnDelete();
        });

        Schema::table('employee_work_schedules', function (Blueprint $table): void {
            $table->uuid('pattern_id')->nullable();
            $table->unsignedSmallInteger('cycle_offset')->default(0);
            $table->date('cycle_anchor_on')->nullable();
            $table->foreign('pattern_id')->references('id')->on('work_schedule_patterns')->restrictOnDelete();
        });

        // Plan is NOT actual attendance. It is a separately versioned day-by-day projection.
        Schema::create('employee_work_plans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('employee_id');
            $table->uuid('schedule_assignment_id');
            $table->uuid('pattern_id');
            $table->date('work_date');
            $table->string('day_kind', 24); // work or rest
            $table->unsignedSmallInteger('planned_minutes');
            $table->string('shift_code', 32)->nullable();
            $table->string('source', 24)->default('cycle');
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->unique(['employee_id', 'work_date']);
            $table->index(['company_id', 'work_date']);
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('employee_id')->references('id')->on('employees')->restrictOnDelete();
            $table->foreign('schedule_assignment_id')->references('id')->on('employee_work_schedules')->restrictOnDelete();
            $table->foreign('pattern_id')->references('id')->on('work_schedule_patterns')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_work_plans');
        Schema::table('employee_work_schedules', function (Blueprint $table): void {
            $table->dropForeign(['pattern_id']);
            $table->dropColumn(['pattern_id', 'cycle_offset', 'cycle_anchor_on']);
        });
        Schema::dropIfExists('work_schedule_patterns');
    }
};

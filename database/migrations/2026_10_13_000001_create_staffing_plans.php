<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staffing_plans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('name', 255);
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->string('status', 24)->default('draft');
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();

            $table->index(['company_id', 'status', 'valid_from']);
            $table->index(['company_id', 'valid_from', 'valid_to']);
        });

        Schema::create('staffing_plan_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('staffing_plan_id')->constrained('staffing_plans')->cascadeOnDelete();
            $table->foreignUuid('department_id')->constrained('departments')->restrictOnDelete();
            $table->foreignUuid('position_id')->constrained('positions')->restrictOnDelete();
            $table->decimal('planned_units', 8, 2);
            $table->decimal('base_salary', 14, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();

            $table->unique(
                ['staffing_plan_id', 'department_id', 'position_id'],
                'staffing_plan_line_unique'
            );
            $table->index(['department_id', 'position_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staffing_plan_lines');
        Schema::dropIfExists('staffing_plans');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personnel_actions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignUuid('employee_id')->constrained('employees')->restrictOnDelete();
            $table->string('action_type', 24);
            $table->date('effective_on');
            $table->string('basis_reference', 255);
            $table->text('notes')->nullable();
            $table->foreignUuid('source_assignment_id')->nullable()->constrained('employee_assignments')->restrictOnDelete();
            $table->foreignUuid('target_assignment_id')->nullable()->constrained('employee_assignments')->restrictOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'effective_on']);
            $table->index(['company_id', 'action_type', 'effective_on']);
            $table->index(['employee_id', 'effective_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personnel_actions');
    }
};

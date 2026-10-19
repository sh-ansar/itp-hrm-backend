<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('personnel_documents', function (Blueprint $table): void {
            // Fail closed for all existing records. Distribution must be explicit.
            $table->boolean('employee_visible')->default(false);
            $table->timestamp('employee_shared_at')->nullable();
            $table->index(['company_id', 'employee_id', 'employee_visible'], 'personnel_docs_employee_visibility');
        });
    }

    public function down(): void
    {
        Schema::table('personnel_documents', function (Blueprint $table): void {
            $table->dropIndex('personnel_docs_employee_visibility');
            $table->dropColumn(['employee_visible', 'employee_shared_at']);
        });
    }
};

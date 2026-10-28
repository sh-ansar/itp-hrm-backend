<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table): void {
            $table->foreignId('assigned_manager_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->uuid('manager_department_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table): void {
            $table->dropForeign(['assigned_manager_user_id']);
            $table->dropColumn(['assigned_manager_user_id', 'manager_department_id']);
        });
    }
};

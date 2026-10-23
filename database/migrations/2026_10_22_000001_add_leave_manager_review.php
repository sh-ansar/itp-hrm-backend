<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table): void {
            $table->string('manager_recommendation', 16)->nullable();
            $table->foreignId('manager_reviewed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('manager_reviewed_at')->nullable();
            $table->text('manager_decision_comment')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table): void {
            $table->dropForeign(['manager_reviewed_by_user_id']);
            $table->dropColumn(['manager_recommendation', 'manager_reviewed_by_user_id',
                'manager_reviewed_at', 'manager_decision_comment']);
        });
    }
};

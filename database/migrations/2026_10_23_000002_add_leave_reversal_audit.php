<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('employee_leaves', function (Blueprint $table): void {
            $table->timestampTz('cancelled_at')->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('cancellation_reason', 500)->nullable();
        });
        Schema::table('leave_requests', function (Blueprint $table): void {
            $table->timestampTz('reversed_at')->nullable();
            $table->foreignId('reversed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('reversal_reason', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reversed_by_user_id');
            $table->dropColumn(['reversed_at', 'reversal_reason']);
        });
        Schema::table('employee_leaves', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cancelled_by_user_id');
            $table->dropColumn(['cancelled_at', 'cancellation_reason']);
        });
    }
};

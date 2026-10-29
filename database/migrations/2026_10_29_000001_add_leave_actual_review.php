<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('employee_leaves', function (Blueprint $table): void {
            $table->string('actual_review_state', 16)->nullable();
            $table->string('actual_review_note', 500)->nullable();
            $table->timestampTz('actual_reviewed_at')->nullable();
            $table->foreignId('actual_reviewed_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('employee_leaves', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('actual_reviewed_by_user_id');
            $table->dropColumn(['actual_review_state', 'actual_review_note', 'actual_reviewed_at']);
        });
    }
};

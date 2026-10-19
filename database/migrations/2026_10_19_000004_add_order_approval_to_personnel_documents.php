<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Existing PDFs are legacy/draft records; never mark them approved by inference.
        Schema::table('personnel_documents', function (Blueprint $table): void {
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('submitted_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('submitted_at')->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->index(['company_id', 'document_type', 'status'], 'personnel_order_queue_index');
        });
    }

    public function down(): void
    {
        Schema::table('personnel_documents', function (Blueprint $table): void {
            $table->dropIndex('personnel_order_queue_index');
            $table->dropConstrainedForeignId('reviewed_by_user_id');
            $table->dropConstrainedForeignId('submitted_by_user_id');
            $table->dropConstrainedForeignId('created_by_user_id');
            $table->dropColumn(['submitted_at', 'reviewed_at', 'review_note']);
        });
    }
};

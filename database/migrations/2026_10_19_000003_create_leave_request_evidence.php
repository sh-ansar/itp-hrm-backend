<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // A sealed private supporting PDF for a protected-leave request. Medical
        // specifics never enter the public leave schedule or audit context.
        Schema::create('leave_request_evidence', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('leave_request_id')->unique();
            $table->string('storage_path', 500);
            $table->unsignedInteger('byte_size');
            $table->char('sha256', 64);
            $table->string('status', 20)->default('pending'); // pending|verified|rejected
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('uploaded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status'], 'leave_evidence_company_status');
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('leave_request_id')->references('id')->on('leave_requests')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_request_evidence');
    }
};

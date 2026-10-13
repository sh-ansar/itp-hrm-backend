<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('personnel_documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies');
            $table->foreignUuid('employee_id')->constrained('employees');
            $table->string('document_type', 48);
            $table->string('document_number', 128)->nullable();
            $table->string('title', 255);
            $table->date('issued_on')->nullable();
            $table->string('status', 24)->default('draft');
            $table->string('storage_disk', 64)->nullable();
            $table->string('storage_path', 512)->nullable();
            $table->string('original_filename', 255)->nullable();
            $table->string('content_type', 128)->nullable();
            $table->unsignedBigInteger('byte_size')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->uuid('personnel_action_id')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->foreign('personnel_action_id')->references('id')->on('personnel_actions');
            $table->index(['company_id', 'issued_on', 'id'], 'personnel_documents_company_dates');
            $table->index(['company_id', 'employee_id'], 'personnel_documents_employee');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personnel_documents');
    }
};

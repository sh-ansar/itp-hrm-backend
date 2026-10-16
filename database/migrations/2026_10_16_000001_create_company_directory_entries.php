<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_directory_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('group_code', 64);
            $table->string('code', 100);
            $table->string('name', 255);
            $table->string('category', 120)->nullable();
            $table->string('unit', 80)->nullable();
            $table->string('value', 500)->nullable();
            $table->string('status', 16)->default('active');
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->unique(['company_id','group_code','code'], 'directory_company_group_code_unique');
            $table->index(['company_id','group_code','status'], 'directory_company_group_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_directory_entries');
    }
};

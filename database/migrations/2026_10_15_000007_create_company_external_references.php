<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_external_references', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('source_system', 64);
            $table->string('source_id', 128);
            $table->string('source_name', 255);
            $table->string('legal_address', 500)->nullable();
            $table->string('physical_address', 500)->nullable();
            $table->string('phone', 64)->nullable();
            $table->timestamps();

            $table->unique(['source_system', 'source_id'], 'company_external_source_unique');
            $table->unique(['company_id', 'source_system'], 'company_external_company_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_external_references');
    }
};

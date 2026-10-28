<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('personnel_documents', function (Blueprint $table): void {
            $table->text('description')->nullable();
            $table->json('tags')->nullable();
            $table->string('section_key', 40)->nullable();
            $table->index(['company_id', 'section_key', 'document_type'], 'personnel_documents_company_section');
        });
    }

    public function down(): void
    {
        Schema::table('personnel_documents', function (Blueprint $table): void {
            $table->dropIndex('personnel_documents_company_section');
            $table->dropColumn(['description', 'tags', 'section_key']);
        });
    }
};

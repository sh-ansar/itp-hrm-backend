<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('personnel_documents', function (Blueprint $table): void {
            $table->text('description')->nullable();
            $table->json('tags')->nullable();
            $table->string('section_key', 40)->nullable();
            // Persist Unicode-normalized tokens: SQLite LOWER() does not lowercase Cyrillic.
            $table->text('search_index')->nullable();
            $table->text('tag_index')->nullable();
            $table->index(['company_id', 'section_key', 'document_type'], 'personnel_documents_company_section');
        });
        // Keep existing document titles searchable without changing their visibility.
        DB::table('personnel_documents')->orderBy('id')->chunk(100, function ($rows): void {
            foreach ($rows as $document) {
                DB::table('personnel_documents')->where('id', $document->id)->update([
                    'search_index' => mb_strtolower(implode(' ', array_filter([
                        $document->title, $document->document_number,
                    ]))),
                    'tag_index' => '',
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('personnel_documents', function (Blueprint $table): void {
            $table->dropIndex('personnel_documents_company_section');
            $table->dropColumn(['description', 'tags', 'section_key', 'search_index', 'tag_index']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('company_directory_entries', function (Blueprint $table): void {
            $table->foreignUuid('parent_id')->nullable()->after('group_code')
                ->constrained('company_directory_entries')->restrictOnDelete();
            $table->index(['company_id', 'group_code', 'parent_id'], 'directory_company_group_parent_idx');
        });
    }
    public function down(): void {
        Schema::table('company_directory_entries', function (Blueprint $table): void {
            $table->dropForeign(['parent_id']);
            $table->dropIndex('directory_company_group_parent_idx');
            $table->dropColumn('parent_id');
        });
    }
};

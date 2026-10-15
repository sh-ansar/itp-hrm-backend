<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('leave_types', function (Blueprint $table): void {
            $table->string('category', 32)->default('ordinary');
        });
    }

    public function down(): void
    {
        Schema::table('leave_types', fn (Blueprint $table) => $table->dropColumn('category'));
    }
};

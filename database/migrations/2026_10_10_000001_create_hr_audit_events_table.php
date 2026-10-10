<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_audit_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('subject_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('action', 64);
            $table->json('context')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['company_id', 'created_at']);
            $table->index(['actor_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_audit_events');
    }
};

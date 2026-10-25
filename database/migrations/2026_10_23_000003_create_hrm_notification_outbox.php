<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('hrm_notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('audit_event_id')->constrained('hr_audit_events')->restrictOnDelete();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('employee_id')->nullable();
            $table->string('event', 64);
            $table->string('rule', 32);
            $table->char('locale', 2)->default('ru');
            $table->uuid('correlation_id');
            $table->boolean('in_app')->default(true);
            $table->timestampTz('read_at')->nullable();
            $table->string('mail_state', 16)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('available_at');
            $table->timestampTz('locked_until')->nullable();
            $table->uuid('lease_token')->nullable();
            $table->timestampTz('accepted_at')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->timestampsTz();
            $table->unique(['audit_event_id', 'user_id']);
            $table->index(['mail_state', 'available_at']);
            $table->index(['user_id', 'company_id', 'created_at']);
        });
        Schema::create('hrm_notification_deliveries', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignUuid('notification_id')->constrained('hrm_notifications')->restrictOnDelete();
            $table->unsignedSmallInteger('attempt');
            $table->string('result', 24);
            $table->string('error_code', 64)->nullable();
            $table->timestampTz('created_at');
            $table->unique(['notification_id', 'attempt']);
        });
        Schema::table('user_preferences', function (Blueprint $table): void {
            $table->char('locale', 2)->default('ru');
        });
    }

    public function down(): void
    {
        Schema::table('user_preferences', fn (Blueprint $table) => $table->dropColumn('locale'));
        Schema::dropIfExists('hrm_notification_deliveries');
        Schema::dropIfExists('hrm_notifications');
    }
};

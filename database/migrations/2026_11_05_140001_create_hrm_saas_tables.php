<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('hrm_platform_admin_grants', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->string('permission', 48);
            $t->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['user_id', 'permission'], 'hrm_platform_grant_unique');
        });
        Schema::create('hrm_plan_versions', function (Blueprint $t): void {
            $t->id();
            $t->string('code', 48);
            $t->unsignedInteger('version');
            $t->json('names');
            $t->json('descriptions')->nullable();
            $t->unsignedBigInteger('monthly_price_minor')->default(0);
            $t->string('currency', 3)->default('KZT');
            $t->json('limits');
            $t->json('modules');
            $t->unsignedInteger('period_months')->default(1);
            $t->integer('display_order')->default(0);
            $t->char('sha256', 64)->nullable();
            $t->string('status', 16)->default('draft');
            $t->boolean('is_public')->default(false);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['code', 'version'], 'hrm_plan_code_version_unique');
            $t->index(['status', 'is_public']);
        });
        Schema::create('hrm_company_subscriptions', function (Blueprint $t): void {
            $t->id();
            $t->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete()->unique();
            $t->foreignId('plan_version_id')->constrained('hrm_plan_versions');
            $t->json('snapshot')->nullable();
            $t->string('status', 20)->default('active');
            $t->timestamp('starts_at')->nullable();
            $t->timestamp('ends_at')->nullable();
            $t->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
        Schema::create('hrm_invoice_profiles', function (Blueprint $t): void {
            $t->id();
            $t->string('scope', 16)->default('supplier');
            $t->foreignUuid('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $t->json('details');
            $t->timestamps();
            $t->index(['scope', 'company_id']);
        });
        Schema::create('hrm_invoices', function (Blueprint $t): void {
            $t->id();
            $t->string('number', 40)->unique();
            $t->foreignUuid('company_id')->constrained('companies');
            $t->foreignId('plan_version_id')->nullable()->constrained('hrm_plan_versions');
            $t->unsignedBigInteger('subtotal_minor');
            $t->unsignedBigInteger('tax_minor')->default(0);
            $t->unsignedBigInteger('total_minor');
            $t->string('currency', 3)->default('KZT');
            $t->string('status', 24)->default('draft');
            $t->date('due_date')->nullable();
            $t->timestamp('issued_at')->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->string('payment_reference', 120)->nullable()->unique();
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->json('snapshot');
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['company_id', 'status']);
        });
        Schema::create('hrm_payment_proofs', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('invoice_id')->constrained('hrm_invoices')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained('users');
            $t->string('path', 400);
            $t->string('sha256', 64);
            $t->string('mime', 60);
            $t->unsignedInteger('size');
            $t->string('status', 16)->default('pending');
            $t->string('review_note', 500)->nullable();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamps();
        });
        Schema::create('hrm_legal_versions', function (Blueprint $t): void {
            $t->id();
            $t->string('type', 48);
            $t->string('locale', 2);
            $t->string('jurisdiction', 2)->default('KZ');
            $t->unsignedInteger('version');
            $t->string('title', 200);
            $t->longText('body');
            $t->json('operator_snapshot')->nullable();
            $t->char('sha256', 64)->nullable();
            $t->string('acceptance', 20)->default('accepted');
            $t->text('approval_note')->nullable();
            $t->string('status', 16)->default('draft');
            $t->boolean('required')->default(false);
            $t->timestamp('effective_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('published_at')->nullable();
            $t->timestamps();
            $t->unique(['type', 'locale', 'jurisdiction', 'version'], 'hrm_legal_type_locale_version_unique');
            $t->index(['jurisdiction', 'status', 'effective_at'], 'hrm_legal_active_by_jurisdiction');
        });
        Schema::create('hrm_legal_consents', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('legal_version_id')->constrained('hrm_legal_versions');
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->foreignUuid('company_id')->nullable()->constrained('companies');
            $t->string('ip_hash', 64)->nullable();
            $t->char('sha256', 64)->nullable();
            $t->string('action', 20)->default('accepted');
            $t->string('source', 32)->default('registration');
            $t->string('locale', 2)->nullable();
            $t->string('jurisdiction', 2)->default('KZ');
            $t->timestamp('accepted_at');
            $t->unique(['legal_version_id', 'user_id'], 'hrm_legal_acceptance_unique');
        });
        Schema::create('hrm_commercial_audits', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignUuid('company_id')->nullable()->constrained('companies');
            $t->string('action', 80);
            $t->string('subject', 160);
            $t->json('details')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['company_id', 'created_at']);
        });
        Schema::create('hrm_company_legal_context', function (Blueprint $t): void {
            $t->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete()->primary();
            $t->string('jurisdiction', 2);
            $t->string('registration_locale', 2);
            $t->timestamps();
        });
        Schema::create('hrm_registration_challenges', function (Blueprint $t): void {
            $t->id();
            $t->string('email', 254)->unique();
            $t->string('jurisdiction', 2)->default('KZ');
            $t->string('locale', 2)->default('ru');
            $t->string('code_hash', 64);
            $t->string('registration_token_hash', 64)->nullable();
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->timestamp('expires_at');
            $t->timestamp('verified_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach ([
            'hrm_registration_challenges', 'hrm_company_legal_context', 'hrm_commercial_audits', 'hrm_legal_consents',
            'hrm_legal_versions', 'hrm_payment_proofs', 'hrm_invoices',
            'hrm_invoice_profiles', 'hrm_company_subscriptions', 'hrm_plan_versions',
            'hrm_platform_admin_grants',
        ] as $table) Schema::dropIfExists($table);
    }
};

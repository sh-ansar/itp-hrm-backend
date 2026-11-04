<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_role_templates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('code', 48);
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->string('base_role', 48);
            $table->json('capabilities');
            $table->string('status', 16)->default('active');
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->unique(['company_id', 'code'], 'company_role_templates_code_unique');
            $table->unique(['id', 'company_id'], 'company_role_templates_company_reference');
            $table->index(['company_id', 'status']);
        });

        Schema::table('company_user_roles', function (Blueprint $table): void {
            $table->foreignUuid('custom_role_id')->nullable();
            $table->foreign(['custom_role_id', 'company_id'], 'company_membership_role_template_fk')
                ->references(['id', 'company_id'])
                ->on('company_role_templates')
                ->restrictOnDelete();
            $table->index(['company_id', 'custom_role_id'], 'company_membership_role_template_idx');
        });
    }

    public function down(): void
    {
        Schema::table('company_user_roles', function (Blueprint $table): void {
            $table->dropForeign('company_membership_role_template_fk');
            $table->dropIndex('company_membership_role_template_idx');
            $table->dropColumn('custom_role_id');
        });
        Schema::dropIfExists('company_role_templates');
    }
};

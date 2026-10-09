<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Permit a composite FK so a department scope cannot point to another company.
        Schema::table('departments', function (Blueprint $table): void {
            $table->unique(['id', 'company_id'], 'departments_company_reference');
        });

        Schema::create('company_user_roles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('role_code', 48);
            $table->timestamps();

            $table->unique(['user_id', 'company_id'], 'company_user_roles_unique');
            $table->index(['company_id', 'role_code']);
        });

        Schema::create('department_access_scopes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id');
            $table->foreignUuid('company_id');
            $table->uuid('department_id');
            $table->timestamps();

            $table->unique(['user_id', 'company_id', 'department_id'], 'department_scopes_unique');

            $table->foreign(['user_id', 'company_id'], 'department_scope_membership_fk')
                ->references(['user_id', 'company_id'])
                ->on('company_user_roles')
                ->cascadeOnDelete();

            $table->foreign(['department_id', 'company_id'], 'department_scope_company_fk')
                ->references(['id', 'company_id'])
                ->on('departments')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('department_access_scopes');
        Schema::dropIfExists('company_user_roles');

        Schema::table('departments', function (Blueprint $table): void {
            $table->dropUnique('departments_company_reference');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive, normalized private employee records. Never store identity numbers
 * on the public employees registry or in audit context.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_private_profiles', function (Blueprint $table): void {
            $table->uuid('employee_id')->primary();
            $table->uuid('company_id');
            $table->string('login_name', 100)->nullable();
            $table->text('iin_ciphertext')->nullable();
            $table->string('nationality', 100)->nullable();
            $table->string('citizenship', 100)->nullable();
            $table->string('marital_status', 80)->nullable();
            $table->string('residence_address', 500)->nullable();
            $table->string('registration_address', 500)->nullable();
            $table->string('address_entrance', 40)->nullable();
            $table->string('address_floor', 20)->nullable();
            $table->string('address_apartment', 40)->nullable();
            $table->string('place_of_birth', 500)->nullable();
            $table->string('emergency_contact_name', 255)->nullable();
            $table->string('emergency_contact_phone', 40)->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->foreign(['employee_id', 'company_id'], 'private_profile_employee_company_fk')
                ->references(['id', 'company_id'])->on('employees')->restrictOnDelete();
            $table->index('company_id');
        });

        Schema::create('employee_military_registration', function (Blueprint $table): void {
            $table->uuid('employee_id')->primary();
            $table->uuid('company_id');
            $table->string('status', 100)->nullable();
            $table->string('category', 100)->nullable();
            // Reference only. Uploads are managed by an authorized private
            // document subsystem, not by unsanitized filesystem paths here.
            $table->string('document_reference', 255)->nullable();
            $table->text('comment')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->foreign(['employee_id', 'company_id'], 'military_profile_employee_company_fk')
                ->references(['id', 'company_id'])->on('employees')->restrictOnDelete();
            $table->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_military_registration');
        Schema::dropIfExists('employee_private_profiles');
    }
};

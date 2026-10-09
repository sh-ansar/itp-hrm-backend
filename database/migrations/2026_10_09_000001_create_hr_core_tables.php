<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('companies', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->string('name'); $t->timestamps();
        });
        Schema::create('departments', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('company_id')->constrained('companies');
            $t->foreignUuid('parent_id')->nullable()->constrained('departments');
            $t->string('name'); $t->timestamps();
        });
        Schema::create('positions', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('company_id')->constrained('companies');
            $t->string('name'); $t->timestamps();
        });
        Schema::create('employees', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('company_id')->constrained('companies');
            $t->string('personnel_number'); $t->string('last_name'); $t->string('first_name');
            $t->string('middle_name')->nullable(); $t->timestamps();
            $t->unique(['company_id', 'personnel_number']);
        });
        Schema::create('employee_assignments', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('employee_id')->constrained('employees');
            $t->foreignUuid('department_id')->constrained('departments');
            $t->foreignUuid('position_id')->constrained('positions');
            $t->date('valid_from'); $t->date('valid_to')->nullable();
            $t->string('basis_reference')->nullable(); $t->timestamps();
            $t->index(['employee_id','valid_from','valid_to']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('employee_assignments'); Schema::dropIfExists('employees');
        Schema::dropIfExists('positions'); Schema::dropIfExists('departments'); Schema::dropIfExists('companies');
    }
};

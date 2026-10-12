<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('employee_experience', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('employee_id')->constrained('employees');
            $t->string('experience_type', 80);
            $t->date('started_on');
            $t->date('ended_on')->nullable();
            $t->string('basis_reference')->nullable();
            $t->timestamps();
            $t->index(['employee_id','started_on']);
        });
        Schema::create('employee_reserve', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('employee_id')->constrained('employees');
            $t->string('target_position');
            $t->string('reserve_level', 80);
            $t->string('reserve_type', 80);
            $t->unsignedSmallInteger('joined_year');
            $t->unsignedSmallInteger('left_year')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->index(['employee_id','joined_year']);
        });
        Schema::create('employee_training', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('employee_id')->constrained('employees');
            $t->string('training_type', 80);
            $t->string('training_mode', 80);
            $t->string('topic');
            $t->string('provider')->nullable();
            $t->string('city')->nullable();
            $t->date('started_on');
            $t->date('ended_on')->nullable();
            $t->string('document_reference')->nullable();
            $t->timestamps();
            $t->index(['employee_id','started_on']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('employee_training');
        Schema::dropIfExists('employee_reserve');
        Schema::dropIfExists('employee_experience');
    }
};

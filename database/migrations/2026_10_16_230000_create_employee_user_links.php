<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('employee_user_links', function (Blueprint $table): void {
            $table->uuid('company_id');
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('employee_id')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->primary(['company_id', 'user_id']);
            $table->unique(['company_id', 'employee_id']);
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['employee_id', 'company_id'])->references(['id', 'company_id'])
                ->on('employees')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_user_links');
    }
};

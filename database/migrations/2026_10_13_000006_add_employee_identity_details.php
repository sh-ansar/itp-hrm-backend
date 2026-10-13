<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('employees', function (Blueprint $t): void {
            $t->date('birth_date')->nullable();
            $t->string('gender', 16)->nullable();
            $t->string('email', 255)->nullable();
            $t->string('phone', 40)->nullable();
            $t->string('address', 500)->nullable();
        });
    }
    public function down(): void {
        Schema::table('employees', function (Blueprint $t): void {
            $t->dropColumn(['birth_date', 'gender', 'email', 'phone', 'address']);
        });
    }
};

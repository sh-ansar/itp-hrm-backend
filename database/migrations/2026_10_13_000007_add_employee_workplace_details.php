<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
  public function up(): void {
    Schema::table('employees', function (Blueprint $table): void {
      $table->string('work_site', 255)->nullable();
      $table->string('work_zone', 255)->nullable();
      $table->string('work_team', 255)->nullable();
      $table->string('supervisor_name', 255)->nullable();
    });
  }
  public function down(): void {
    Schema::table('employees', function (Blueprint $table): void {
      $table->dropColumn(['work_site', 'work_zone', 'work_team', 'supervisor_name']);
    });
  }
};

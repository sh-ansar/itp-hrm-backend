<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('leave_balance_periods', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('employee_id');
            $table->uuid('leave_type_id');
            $table->unsignedSmallInteger('year');
            // Explicitly entered, reviewed entitlement. The leave type's annual_days
            // is NOT implicitly granted to every employee.
            $table->unsignedSmallInteger('granted_days');
            $table->integer('adjustment_days')->default(0);
            $table->unsignedSmallInteger('carried_days')->default(0);
            $table->uuid('carry_from_period_id')->nullable()->unique();
            $table->timestampTz('closed_at')->nullable();
            $table->integer('closing_available_days')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();

            $table->unique(['company_id', 'employee_id', 'leave_type_id', 'year'], 'leave_balance_period_scope_year');
            $table->index(['company_id', 'year'], 'leave_balance_company_year');
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['employee_id', 'company_id'], 'leave_balance_employee_tenant_fk')
                ->references(['id', 'company_id'])->on('employees')->restrictOnDelete();
            $table->foreign(['leave_type_id', 'company_id'], 'leave_balance_type_tenant_fk')
                ->references(['id', 'company_id'])->on('leave_types')->restrictOnDelete();
            // Self-referential FK added after table creation; PostgreSQL must see its primary key.
        });

        Schema::table('leave_balance_periods', function (Blueprint $table): void {
            $table->foreign('carry_from_period_id')->references('id')
                ->on('leave_balance_periods')->restrictOnDelete();
        });

        Schema::create('leave_balance_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('period_id');
            $table->string('kind', 24); // grant | adjustment | carryover | closure
            $table->integer('delta_days'); // Signed accounting entry (closure = 0)
            $table->string('reason', 500);
            $table->uuid('source_period_id')->nullable();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at');
            $table->index(['period_id', 'created_at'], 'leave_balance_entry_period_date');
            $table->foreign('period_id')->references('id')->on('leave_balance_periods')->restrictOnDelete();
            $table->foreign('source_period_id')->references('id')->on('leave_balance_periods')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_balance_entries');
        Schema::dropIfExists('leave_balance_periods');
    }
};

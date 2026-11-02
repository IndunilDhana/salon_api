<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // No foreign keys to central tables: these tables may later move to a dedicated database.
        Schema::connection('tenant')->create('staff', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('job_title');
            $table->unsignedBigInteger('base_salary_minor')->default(0);
            $table->unsignedInteger('commission_bps')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'id']);
        });
        Schema::connection('tenant')->create('appointments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('staff_id');
            $table->string('customer_name');
            $table->string('service_name');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->unsignedBigInteger('price_minor');
            $table->unsignedInteger('commission_bps');
            $table->string('status')->default('booked');
            $table->timestamps();
            $table->index(['tenant_id', 'starts_at']);
            $table->foreign(['tenant_id', 'staff_id'])->references(['tenant_id', 'id'])->on('staff');
        });
        Schema::connection('tenant')->create('payroll_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status')->default('draft');
            $table->unsignedBigInteger('total_minor')->default(0);
            $table->uuid('created_by');
            $table->timestamps();
            $table->unique(['tenant_id', 'period_start']);
            $table->unique(['tenant_id', 'id']);
        });
        Schema::connection('tenant')->create('payroll_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('payroll_run_id');
            $table->uuid('staff_id');
            $table->string('staff_name');
            $table->unsignedBigInteger('base_salary_minor');
            $table->unsignedBigInteger('commission_minor');
            $table->unsignedBigInteger('allowance_minor')->default(0);
            $table->unsignedBigInteger('deduction_minor')->default(0);
            $table->unsignedBigInteger('net_minor');
            $table->timestamps();
            $table->unique(['tenant_id', 'payroll_run_id', 'staff_id']);
            $table->foreign(['tenant_id', 'payroll_run_id'])->references(['tenant_id', 'id'])->on('payroll_runs')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'staff_id'])->references(['tenant_id', 'id'])->on('staff');
        });
    }

    public function down(): void
    {
        foreach (['payroll_items', 'payroll_runs', 'appointments', 'staff'] as $table) {
            Schema::connection('tenant')->dropIfExists($table);
        }
    }
};

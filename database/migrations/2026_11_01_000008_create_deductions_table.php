<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->create('deductions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('type', 32)->index(); // 'business' or 'staff_advance'
            $table->string('category', 64)->nullable()->index();
            $table->uuid('staff_id')->nullable()->index();
            $table->string('staff_name', 120)->nullable();
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3)->default('LKR');
            $table->date('date')->index();
            $table->string('payment_method', 32)->default('cash');
            $table->text('notes')->nullable();
            $table->uuid('created_by')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('deductions');
    }
};

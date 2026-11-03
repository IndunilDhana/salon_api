<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Branches table on tenant connection
        Schema::connection('tenant')->create('branches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('name', 100);
            $table->string('code', 32)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('address', 255)->nullable();
            $table->boolean('is_main')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
            $table->unique(['tenant_id', 'id']);
        });

        // 2. Add branch_id to existing tenant tables
        Schema::connection('tenant')->table('orders', function (Blueprint $table) {
            $table->uuid('branch_id')->nullable()->index();
        });

        Schema::connection('tenant')->table('appointments', function (Blueprint $table) {
            $table->uuid('branch_id')->nullable()->index();
        });

        Schema::connection('tenant')->table('deductions', function (Blueprint $table) {
            $table->uuid('branch_id')->nullable()->index();
        });

        Schema::connection('tenant')->table('staff', function (Blueprint $table) {
            $table->uuid('branch_id')->nullable()->index();
        });

        // 3. Branch user access table on central connection
        Schema::connection('central')->create('branch_users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('branch_id')->index();
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id', 'branch_id']);
        });

        // 4. Seed a default "Main Branch" for each existing tenant and link existing data
        $tenants = DB::connection('central')->table('tenants')->get();
        $now = now();

        foreach ($tenants as $tenant) {
            $branchId = (string) Str::uuid();
            DB::connection('tenant')->table('branches')->insert([
                'id' => $branchId,
                'tenant_id' => $tenant->id,
                'name' => 'Main Branch',
                'code' => 'MAIN',
                'is_main' => true,
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // Assign existing tenant records to this main branch
            DB::connection('tenant')->table('orders')->where('tenant_id', $tenant->id)->update(['branch_id' => $branchId]);
            DB::connection('tenant')->table('appointments')->where('tenant_id', $tenant->id)->update(['branch_id' => $branchId]);
            DB::connection('tenant')->table('deductions')->where('tenant_id', $tenant->id)->update(['branch_id' => $branchId]);
            DB::connection('tenant')->table('staff')->where('tenant_id', $tenant->id)->update(['branch_id' => $branchId]);
        }
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('branch_users');

        Schema::connection('tenant')->table('staff', function (Blueprint $table) {
            $table->dropColumn('branch_id');
        });

        Schema::connection('tenant')->table('deductions', function (Blueprint $table) {
            $table->dropColumn('branch_id');
        });

        Schema::connection('tenant')->table('appointments', function (Blueprint $table) {
            $table->dropColumn('branch_id');
        });

        Schema::connection('tenant')->table('orders', function (Blueprint $table) {
            $table->dropColumn('branch_id');
        });

        Schema::connection('tenant')->dropIfExists('branches');
    }
};

<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('central')->create('subscription_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->unsignedBigInteger('price_minor');
            $table->string('currency', 3);
            $table->string('billing_interval');
            $table->boolean('active')->default(true);
            $table->json('features');
            $table->timestamps();
        });
        Schema::connection('central')->create('business_subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignUuid('plan_id')->nullable()->constrained('subscription_plans');
            $table->string('status');
            $table->string('plan_name');
            $table->unsignedBigInteger('price_minor');
            $table->string('currency', 3);
            $table->string('billing_interval');
            $table->json('features');
            $table->dateTime('trial_starts_at');
            $table->dateTime('trial_ends_at');
            $table->dateTime('current_period_start');
            $table->dateTime('current_period_end');
            $table->dateTime('cancelled_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('central')->create('subscription_changes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('subscription_id')->constrained('business_subscriptions')->cascadeOnDelete();
            $table->uuid('actor_id')->nullable();
            $table->string('action');
            $table->json('snapshot');
            $table->dateTime('created_at');
        });
        // A one-time transition for existing businesses. Reading/seeding never extends these dates.
        $now = CarbonImmutable::now('UTC');
        foreach (DB::connection('central')->table('tenants')->get() as $tenant) {
            $end = $now->setTimezone($tenant->timezone)->addMonthNoOverflow()->utc();
            $id = (string) Str::uuid();
            $snapshot = ['id' => $id, 'tenant_id' => $tenant->id, 'plan_id' => null, 'status' => 'trialing', 'plan_name' => 'Free trial', 'price_minor' => 0, 'currency' => $tenant->currency, 'billing_interval' => 'month', 'features' => json_encode(['staff', 'appointments', 'catalog', 'orders', 'payroll']), 'trial_starts_at' => $now, 'trial_ends_at' => $end, 'current_period_start' => $now, 'current_period_end' => $end, 'cancelled_at' => null, 'created_at' => $now, 'updated_at' => $now];
            DB::connection('central')->table('business_subscriptions')->insert($snapshot);
            DB::connection('central')->table('subscription_changes')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'subscription_id' => $id, 'actor_id' => null, 'action' => 'legacy_trial_granted', 'snapshot' => json_encode([...$snapshot, 'features' => json_decode($snapshot['features'], true)]), 'created_at' => $now]);
        }
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('subscription_changes');
        Schema::connection('central')->dropIfExists('business_subscriptions');
        Schema::connection('central')->dropIfExists('subscription_plans');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('central')->table('subscription_plans', function (Blueprint $table) {
            $table->unsignedInteger('included_branches')->default(1)->after('billing_interval');
            $table->unsignedBigInteger('price_per_additional_branch_minor')->default(0)->after('included_branches');
        });

        Schema::connection('central')->table('business_subscriptions', function (Blueprint $table) {
            $table->unsignedInteger('included_branches')->default(1)->after('billing_interval');
            $table->unsignedBigInteger('price_per_additional_branch_minor')->default(0)->after('included_branches');
            $table->unsignedInteger('active_branches_count')->default(1)->after('price_per_additional_branch_minor');
            $table->unsignedBigInteger('base_price_minor')->default(0)->after('active_branches_count');
            $table->unsignedBigInteger('additional_branch_fee_minor')->default(0)->after('base_price_minor');
        });

        // Set base_price_minor equal to current price_minor for existing subscriptions
        DB::connection('central')->table('business_subscriptions')->update([
            'base_price_minor' => DB::raw('price_minor'),
            'included_branches' => 1,
            'price_per_additional_branch_minor' => 0,
            'active_branches_count' => 1,
            'additional_branch_fee_minor' => 0,
        ]);
    }

    public function down(): void
    {
        Schema::connection('central')->table('business_subscriptions', function (Blueprint $table) {
            $table->dropColumn([
                'included_branches',
                'price_per_additional_branch_minor',
                'active_branches_count',
                'base_price_minor',
                'additional_branch_fee_minor',
            ]);
        });

        Schema::connection('central')->table('subscription_plans', function (Blueprint $table) {
            $table->dropColumn([
                'included_branches',
                'price_per_additional_branch_minor',
            ]);
        });
    }
};

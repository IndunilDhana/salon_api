<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('central')->hasTable('subscription_renewals')) {
            Schema::connection('central')->table('subscription_renewals', function (Blueprint $table) {
                if (! Schema::connection('central')->hasColumn('subscription_renewals', 'proof_url')) {
                    $table->mediumText('proof_url')->nullable()->after('reference');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection('central')->hasTable('subscription_renewals')) {
            Schema::connection('central')->table('subscription_renewals', function (Blueprint $table) {
                if (Schema::connection('central')->hasColumn('subscription_renewals', 'proof_url')) {
                    $table->dropColumn('proof_url');
                }
            });
        }
    }
};

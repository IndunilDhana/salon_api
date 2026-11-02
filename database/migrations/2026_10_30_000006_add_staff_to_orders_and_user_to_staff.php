<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('staff', function (Blueprint $table) {
            $table->uuid('user_id')->nullable()->index();
        });

        Schema::connection('tenant')->table('orders', function (Blueprint $table) {
            $table->uuid('staff_id')->nullable()->index();
            $table->string('staff_name', 120)->nullable();
            $table->unsignedInteger('commission_bps')->default(0);
            $table->unsignedBigInteger('commission_minor')->default(0);
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('orders', function (Blueprint $table) {
            $table->dropColumn(['staff_id', 'staff_name', 'commission_bps', 'commission_minor']);
        });

        Schema::connection('tenant')->table('staff', function (Blueprint $table) {
            $table->dropColumn('user_id');
        });
    }
};

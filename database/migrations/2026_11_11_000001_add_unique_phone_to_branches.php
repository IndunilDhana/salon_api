<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::connection('tenant')
            ->table('branches')
            ->select(['tenant_id', 'phone'])
            ->whereNotNull('phone')
            ->groupBy(['tenant_id', 'phone'])
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($duplicates) {
            throw new RuntimeException('Cannot enforce unique branch phone numbers until duplicate values within each workspace are resolved.');
        }

        Schema::connection('tenant')->table('branches', function (Blueprint $table) {
            $table->unique(['tenant_id', 'phone']);
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('branches', function (Blueprint $table) {
            $table->dropUnique('branches_tenant_id_phone_unique');
        });
    }
};

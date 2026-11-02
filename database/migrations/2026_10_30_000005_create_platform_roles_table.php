<?php

use App\Access\PlatformRoleCatalog;
use App\Models\PlatformRole;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('central')->create('platform_roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 100)->unique();
            $table->text('description')->nullable();
            $table->json('permissions');
            $table->boolean('is_super_admin')->default(false);
            $table->timestamps();
        });

        Schema::connection('central')->table('users', function (Blueprint $table) {
            $table->foreignUuid('platform_role_id')->nullable()->constrained('platform_roles')->nullOnDelete();
        });

        $roles = PlatformRoleCatalog::seed();
        $superAdmin = $roles['super_admin'] ?? PlatformRole::where('is_super_admin', true)->first();

        if ($superAdmin) {
            User::where('is_platform_admin', true)->update(['platform_role_id' => $superAdmin->id]);
        }
    }

    public function down(): void
    {
        Schema::connection('central')->table('users', function (Blueprint $table) {
            $table->dropForeign(['platform_role_id']);
            $table->dropColumn('platform_role_id');
        });

        Schema::connection('central')->dropIfExists('platform_roles');
    }
};

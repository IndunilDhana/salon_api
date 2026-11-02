<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('central')->create('tenants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug', 64)->unique();
            $table->string('currency', 3)->default('LKR');
            $table->string('timezone')->default('Asia/Colombo');
            $table->string('storage_mode')->default('shared');
            $table->string('status')->default('active');
            $table->timestamps();
        });
        Schema::connection('central')->create('tenant_roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->json('permissions');
            $table->boolean('is_owner')->default(false);
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
            $table->unique(['tenant_id', 'id']);
        });
        Schema::connection('central')->create('memberships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('role_id');
            $table->string('role');
            $table->unique(['tenant_id', 'user_id']);
            $table->foreign(['tenant_id', 'role_id'])->references(['tenant_id', 'id'])->on('tenant_roles');
        });
        Schema::connection('central')->create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->uuidMorphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('personal_access_tokens');
        Schema::connection('central')->dropIfExists('memberships');
        Schema::connection('central')->dropIfExists('tenant_roles');
        Schema::connection('central')->dropIfExists('tenants');
    }
};

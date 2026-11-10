<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('central')->table('business_subscriptions', function (Blueprint $table) {
            $table->dateTime('grace_period_ends_at')->nullable()->after('current_period_end');
            $table->boolean('access_allowed')->default(false)->after('grace_period_ends_at');
        });

        Schema::connection('central')->create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        Schema::connection('central')->create('subscription_renewals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('subscription_id')->nullable()->constrained('business_subscriptions')->cascadeOnDelete();
            $table->string('payment_method');
            $table->unsignedBigInteger('amount_minor');
            $table->string('reference', 255);
            $table->string('type')->default('late');
            $table->string('status')->default('pending');
            $table->text('notes')->nullable();
            $table->text('review_notes')->nullable();
            $table->foreignUuid('submitted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('subscription_renewals');
        Schema::connection('central')->dropIfExists('notifications');

        Schema::connection('central')->table('business_subscriptions', function (Blueprint $table) {
            $table->dropColumn(['grace_period_ends_at', 'access_allowed']);
        });
    }
};

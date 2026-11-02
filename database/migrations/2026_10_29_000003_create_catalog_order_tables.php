<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->create('catalog_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('type', 16);
            $table->string('name', 120);
            $table->unsignedBigInteger('price_minor');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'id']);
        });
        Schema::connection('tenant')->create('orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('number', 40);
            $table->string('customer_name', 120);
            $table->string('currency', 3);
            $table->string('status', 16)->default('unpaid');
            $table->string('payment_method', 16)->nullable();
            $table->unsignedBigInteger('subtotal_minor');
            $table->unsignedBigInteger('total_minor');
            $table->uuid('created_by');
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'created_at']);
        });
        Schema::connection('tenant')->create('order_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('order_id');
            $table->uuid('catalog_item_id');
            $table->string('name', 120);
            $table->string('type', 16);
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('unit_price_minor');
            $table->unsignedBigInteger('total_minor');
            $table->timestamps();
            $table->unique(['tenant_id', 'order_id', 'catalog_item_id']);
            $table->foreign(['tenant_id', 'order_id'])->references(['tenant_id', 'id'])->on('orders');
            $table->foreign(['tenant_id', 'catalog_item_id'])->references(['tenant_id', 'id'])->on('catalog_items');
        });
    }

    public function down(): void
    {
        foreach (['order_items', 'orders', 'catalog_items'] as $table) {
            Schema::connection('tenant')->dropIfExists($table);
        }
    }
};

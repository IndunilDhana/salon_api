<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('central')->create('notification_records', function (Blueprint $table) {
            $table->string('collection', 20);
            $table->string('record_key', 191);
            $table->json('value');
            $table->timestamps();
            $table->primary(['collection', 'record_key']);
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('notification_records');
    }
};

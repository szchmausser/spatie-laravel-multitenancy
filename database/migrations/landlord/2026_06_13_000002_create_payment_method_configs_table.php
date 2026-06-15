<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('payment_method_configs', function (Blueprint $table) {
            $table->id();
            $table->string('type', 50);
            $table->string('label', 100);
            $table->string('bank_name', 100);
            $table->string('account_number', 20);
            $table->string('account_holder', 100);
            $table->string('holder_id', 20);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('type');
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_method_configs');
    }
};

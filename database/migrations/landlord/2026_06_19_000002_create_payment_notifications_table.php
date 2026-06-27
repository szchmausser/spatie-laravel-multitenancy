<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->create('payment_notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('device_id')->nullable();
            $table->string('bank_code', 20);
            $table->text('raw_text');
            $table->string('dedup_hash', 64)->unique();
            $table->string('parse_status', 20)->default('pending');
            $table->json('parsed_data')->nullable();
            $table->text('parse_error')->nullable();
            $table->timestamp('parsed_at')->nullable();
            $table->timestamps();

            $table->index('bank_code');
            $table->index('parse_status');
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('payment_notifications');
    }
};

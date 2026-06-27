<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->create('devices', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('bank_code', 20);
            $table->string('token', 64)->unique();
            $table->string('android_device_id')->nullable();
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->string('last_heartbeat_ip', 45)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('devices');
    }
};

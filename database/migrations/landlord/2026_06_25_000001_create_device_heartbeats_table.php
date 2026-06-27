<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->create('device_heartbeats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->integer('battery_level')->nullable();
            $table->integer('pending_count')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();

            $table->index('device_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('device_heartbeats');
    }
};

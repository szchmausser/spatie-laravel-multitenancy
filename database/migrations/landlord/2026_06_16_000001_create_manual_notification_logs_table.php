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
        Schema::connection('landlord')->create('manual_notification_logs', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->text('message');
            $table->jsonb('tenant_ids');
            $table->unsignedInteger('total_recipients');
            $table->foreignId('sent_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('manual_notification_logs');
    }
};

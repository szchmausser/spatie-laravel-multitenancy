<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->create('payment_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_notification_id')
                ->constrained('payment_notifications')
                ->cascadeOnDelete();
            $table->foreignId('payment_id')
                ->nullable()
                ->constrained('payments')
                ->nullOnDelete();
            $table->string('parsed_reference', 20)->nullable();
            $table->integer('parsed_amount_cents');
            $table->string('parsed_sender_phone_last4', 4)->nullable();
            $table->string('match_status', 30);
            $table->timestamp('matched_at')->nullable();
            $table->timestamps();

            $table->index('match_status');
            $table->index('payment_id');
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('payment_matches');
    }
};

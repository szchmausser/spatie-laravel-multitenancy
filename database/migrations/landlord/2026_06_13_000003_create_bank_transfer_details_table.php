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
        Schema::create('bank_transfer_details', function (Blueprint $table) {
            $table->foreignId('payment_id')->primary()->constrained('payments')->cascadeOnDelete();
            $table->string('account_number', 20);
            $table->string('bank_name', 100);
            $table->string('account_holder', 100);
            $table->string('holder_id', 20);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bank_transfer_details');
    }
};

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
        Schema::create('pago_movil_details', function (Blueprint $table) {
            $table->foreignId('payment_id')->primary()->constrained('payments')->cascadeOnDelete();
            $table->string('phone', 20);
            $table->string('bank', 100);
            $table->string('rif', 20);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pago_movil_details');
    }
};

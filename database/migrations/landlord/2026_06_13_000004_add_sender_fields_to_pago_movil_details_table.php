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
        Schema::table('pago_movil_details', function (Blueprint $table) {
            $table->string('sender_bank', 100)->after('rif');
            $table->string('sender_phone', 20)->after('sender_bank');
            $table->date('payment_date')->after('sender_phone');
            $table->string('concept', 255)->nullable()->after('payment_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pago_movil_details', function (Blueprint $table) {
            $table->dropColumn(['sender_bank', 'sender_phone', 'payment_date', 'concept']);
        });
    }
};

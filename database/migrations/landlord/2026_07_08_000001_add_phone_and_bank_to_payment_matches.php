<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_matches', function (Blueprint $table) {
            $table->string('parsed_sender_phone_number', 30)->nullable()->after('parsed_amount_cents');
            $table->string('parsed_sender_phone_first4', 4)->nullable()->after('parsed_sender_phone_number');
            $table->string('parsed_bank_code', 10)->nullable()->after('parsed_sender_phone_first4');
        });
    }

    public function down(): void
    {
        Schema::table('payment_matches', function (Blueprint $table) {
            $table->dropColumn(['parsed_sender_phone_number', 'parsed_sender_phone_first4', 'parsed_bank_code']);
        });
    }
};

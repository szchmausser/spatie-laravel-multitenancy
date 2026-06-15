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
        Schema::table('bank_transfer_details', function (Blueprint $table) {
            $table->string('sender_account_number', 20)->nullable()->after('sender_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bank_transfer_details', function (Blueprint $table) {
            $table->dropColumn('sender_account_number');
        });
    }
};

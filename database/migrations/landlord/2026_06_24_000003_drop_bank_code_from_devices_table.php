<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->table('devices', function (Blueprint $table) {
            $table->dropColumn('bank_code');
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('devices', function (Blueprint $table) {
            $table->string('bank_code', 20)->after('name');
        });
    }
};

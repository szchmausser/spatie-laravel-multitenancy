<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->table('payment_notifications', function (Blueprint $table) {
            $table->foreign('device_id')->references('id')->on('devices')->nullOnDelete();
            $table->string('received_at')->nullable()->after('dedup_hash');
            $table->string('android_monto')->nullable()->after('parse_status');
            $table->string('android_telefono')->nullable()->after('android_monto');
            $table->string('android_referencia')->nullable()->after('android_telefono');
            $table->string('android_fecha')->nullable()->after('android_referencia');
            $table->string('android_hora')->nullable()->after('android_fecha');
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('payment_notifications', function (Blueprint $table) {
            $table->dropForeign(['device_id']);
            $table->dropColumn([
                'received_at',
                'android_monto',
                'android_telefono',
                'android_referencia',
                'android_fecha',
                'android_hora',
            ]);
        });
    }
};

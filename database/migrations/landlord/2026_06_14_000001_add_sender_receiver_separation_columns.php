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
        // 1. payments — add payment_method_config_id FK
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('payment_method_config_id')
                ->nullable()
                ->after('payment_method')
                ->constrained('payment_method_configs')
                ->nullOnDelete();
        });

        // 2. pago_movil_details — add sender_id
        Schema::table('pago_movil_details', function (Blueprint $table) {
            $table->string('sender_id', 20)->nullable()->after('sender_phone');
        });

        // 3. bank_transfer_details — add 6 sender fields
        Schema::table('bank_transfer_details', function (Blueprint $table) {
            $table->string('sender_bank', 100)->nullable()->after('holder_id');
            $table->string('sender_name', 100)->nullable()->after('sender_bank');
            $table->string('sender_id', 20)->nullable()->after('sender_name');
            $table->string('tenant_rif', 20)->nullable()->after('sender_id');
            $table->date('payment_date')->nullable()->after('tenant_rif');
            $table->string('concept', 255)->nullable()->after('payment_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['payment_method_config_id']);
            $table->dropColumn('payment_method_config_id');
        });

        Schema::table('pago_movil_details', function (Blueprint $table) {
            $table->dropColumn('sender_id');
        });

        Schema::table('bank_transfer_details', function (Blueprint $table) {
            $table->dropColumn([
                'sender_bank', 'sender_name', 'sender_id',
                'tenant_rif', 'payment_date', 'concept',
            ]);
        });
    }
};

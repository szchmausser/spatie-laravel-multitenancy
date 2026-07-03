<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * 1. Drop existing FK on device_id to redefine it with explicit nullOnDelete.
     * 2. Re-add FK with ON DELETE SET NULL (was already set, reinforced for clarity).
     * 3. Add source_type varchar(20) with default 'bank-app' and an index.
     * 4. Backfill existing rows where source_type is null.
     */
    public function up(): void
    {
        // Step 1 & 2: Drop and re-add the FK with nullOnDelete
        Schema::connection('landlord')->table('payment_notifications', function (Blueprint $table) {
            $table->dropForeign(['device_id']);
        });

        Schema::connection('landlord')->table('payment_notifications', function (Blueprint $table) {
            $table->foreign('device_id')
                ->references('id')
                ->on('devices')
                ->nullOnDelete();
        });

        // Step 3: Add source_type column and index
        Schema::connection('landlord')->table('payment_notifications', function (Blueprint $table) {
            $table->string('source_type', 20)
                ->default('bank-app')
                ->after('device_id');

            $table->index('source_type');
        });

        // Step 4: Backfill existing rows (safe, column default covers new rows)
        DB::connection('landlord')
            ->table('payment_notifications')
            ->whereNull('source_type')
            ->update(['source_type' => 'bank-app']);
    }

    /**
     * Reverse the migration.
     *
     * Drop the index and column. The FK is preserved (it existed before this
     * migration), so we do NOT drop it in the down migration.
     */
    public function down(): void
    {
        Schema::connection('landlord')->table('payment_notifications', function (Blueprint $table) {
            $table->dropIndex(['source_type']);
            $table->dropColumn('source_type');
        });
    }
};

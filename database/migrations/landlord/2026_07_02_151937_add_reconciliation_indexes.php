<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    /**
     * Run the migrations.
     *
     * Add composite indexes to support reconciliation dashboard queries:
     * - (status, created_at): used by the pending() query filtering by status + date range
     * - (verified_at, verified_by): used by autoverified-today KPI and stats queries
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->index(['status', 'created_at']);
            $table->index(['verified_at', 'verified_by']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['status', 'created_at']);
            $table->dropIndex(['verified_at', 'verified_by']);
        });
    }
};

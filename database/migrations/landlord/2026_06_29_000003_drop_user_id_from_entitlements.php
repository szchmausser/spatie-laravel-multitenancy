<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Entitlements are now tenant-level: one row per (tenant, resource)
     * grants access to ALL users of that tenant. The `user_id` column
     * is no longer needed. The unique constraint is replaced from
     * (tenant_id, user_id, resource_id) to (tenant_id, resource_id).
     *
     * The old (tenant_id, user_id) index is also dropped since
     * lookups no longer filter by user.
     */
    public function up(): void
    {
        Schema::table('entitlements', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'user_id', 'resource_id']);
            $table->dropIndex(['tenant_id', 'user_id']);
            $table->dropColumn('user_id');

            $table->unique(['tenant_id', 'resource_id']);
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::table('entitlements', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'resource_id']);

            $table->unsignedBigInteger('user_id');
            $table->unique(['tenant_id', 'user_id', 'resource_id']);
            $table->index(['tenant_id', 'user_id']);
        });
    }
};

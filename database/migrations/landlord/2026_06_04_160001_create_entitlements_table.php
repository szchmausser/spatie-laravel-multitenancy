<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The `entitlements` table records "this user from this tenant
     * is allowed to download this resource." It lives in the
     * landlord database alongside the resources catalog and the
     * subscription plan, so a single query answers "can this user
     * download this resource?" without crossing connection
     * boundaries.
     *
     * `user_id` is intentionally NOT a foreign key: the `User`
     * model lives in the tenant's database (UsesTenantConnection),
     * so an enforced FK would point at a table that does not
     * exist in the landlord connection. We denormalise
     * `tenant_id + user_id` to make the common "all my
     * entitlements" lookup a single indexed scan.
     *
     * The UNIQUE(tenant_id, user_id, resource_id) constraint is
     * the second layer of defence against duplicate grants; the
     * first is `updateOrCreate` in the controller.
     */
    public function up(): void
    {
        Schema::create('entitlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->foreignId('resource_id')->constrained('resources')->cascadeOnDelete();
            $table->string('granted_via');
            $table->timestamp('granted_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id', 'resource_id']);
            $table->index(['tenant_id', 'user_id']);
            $table->index('resource_id');
            $table->index('granted_via');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('entitlements');
    }
};

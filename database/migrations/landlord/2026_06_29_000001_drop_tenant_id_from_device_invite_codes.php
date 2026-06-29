<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->table('device_invite_codes', function (Blueprint $table) {
            $table->dropForeign('device_invite_codes_tenant_id_foreign');
            $table->dropColumn('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('device_invite_codes', function (Blueprint $table) {
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
        });
    }
};

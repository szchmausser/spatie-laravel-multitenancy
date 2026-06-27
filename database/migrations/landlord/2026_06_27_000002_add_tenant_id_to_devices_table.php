<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->table('devices', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('devices', function (Blueprint $table) {
            $table->dropForeign(['tenant_id']);
            $table->dropColumn('tenant_id');
        });
    }
};

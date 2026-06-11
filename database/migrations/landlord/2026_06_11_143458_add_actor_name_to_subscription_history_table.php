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
        Schema::connection('landlord')->table('subscription_history', function (Blueprint $table) {
            $table->string('actor_name')->nullable()->after('actor_id');
            $table->string('actor_email')->nullable()->after('actor_name');
            $table->string('actor_type', 20)->nullable()->after('actor_email');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('landlord')->table('subscription_history', function (Blueprint $table) {
            $table->dropColumn(['actor_name', 'actor_email', 'actor_type']);
        });
    }
};

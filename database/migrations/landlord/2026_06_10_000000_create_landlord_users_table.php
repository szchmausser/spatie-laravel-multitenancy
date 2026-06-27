<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the users table in the landlord database.
     *
     * Several landlord tables (payments, subscription_history,
     * manual_notification_logs, device_invite_codes) reference
     * the users table via foreign keys. Without this table,
     * migrate:fresh fails because the referenced table does
     * not exist.
     *
     * The default Laravel migration also creates `users`, but on
     * the default connection. When both connections share the same
     * database (e.g. testing), this migration is idempotent via
     * hasTable check.
     *
     * Admin users are stored in the landlord connection
     * (shared across all tenants), while regular users are
     * per-tenant in their respective databases.
     */
    public function up(): void
    {
        if (Schema::connection('landlord')->hasTable('users')) {
            return;
        }

        Schema::connection('landlord')->create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Never drop users here — the default migration owns it.
        // If you need to rollback, drop the users table manually.
    }
};

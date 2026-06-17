<?php

namespace Tests\Browser;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Tests\TestCase;

/**
 * Base test case for browser tests.
 *
 * Extends TestCase but overrides refreshDatabase() to skip database
 * transactions — the browser HTTP server runs in a separate process and
 * cannot see uncommitted data.
 *
 * Tables are cleaned up between tests via DELETE in setUp() instead
 * of transactional rollback.
 */
class BrowserTestCase extends TestCase
{
    protected function refreshDatabase(): void
    {
        if (! RefreshDatabaseState::$migrated) {
            $this->artisan('migrate:fresh', $this->migrateFreshUsing());

            $this->artisan('migrate', [
                '--path' => 'database/migrations/landlord',
                '--database' => 'landlord',
                '--force' => true,
            ]);

            $this->app[Kernel::class]->setArtisan(null);
            RefreshDatabaseState::$migrated = true;
        }
        // Intentionally skip database transactions — the browser HTTP
        // server runs in a separate PHP process and cannot see uncommitted
        // data. Per-test cleanup is handled in setUp() instead.
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Clean up between tests since we can't use database transactions.
        // Delete from ALL tables that browser tests write to, in the correct
        // order to respect foreign key constraints (child tables first).
        // Landlord model uses the `users` table on the landlord connection.
        $landlord = $this->app->make('db')->connection('landlord');

        // Child tables first (foreign keys reference parent tables)
        $landlord->table('subscription_history')->delete();
        $landlord->table('payments')->delete();
        $landlord->table('orders')->delete();
        $landlord->table('payment_method_configs')->delete();
        $landlord->table('entitlements')->delete();

        // Parent tables
        $landlord->table('subscriptions')->delete();
        $landlord->table('tenants')->delete();
        $landlord->table('resources')->delete();
        $landlord->table('plans')->delete();
        $landlord->table('users')->delete();
    }
}

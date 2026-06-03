<?php

namespace Tests\Support;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\Attributes\Seed;
use Illuminate\Foundation\Testing\Attributes\Seeder;
use Illuminate\Foundation\Testing\DatabaseTransactionsManager;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Foundation\Testing\TestCase;
use ReflectionClass;

/**
 * Extends Laravel's RefreshDatabase to also handle the landlord connection.
 *
 * Both the default (pgsql) and landlord connections share the same database.
 * When migrate:fresh drops all tables, it wipes the landlord tables too.
 * This trait re-runs landlord migrations after the default refresh cycle
 * and wraps both connections in a database transaction.
 */
trait RefreshLandlordDatabase
{
    /**
     * The connections that should be wrapped in a database transaction.
     *
     * @var array<int, string|null>
     */
    protected array $connectionsToTransact = [null, 'landlord'];

    /**
     * The database connections that should have transactions.
     */
    protected function connectionsToTransact(): array
    {
        return $this->connectionsToTransact;
    }

    /**
     * Refresh the database by running migrate:fresh on the default connection,
     * then re-running landlord migrations (since they share the same DB).
     */
    protected function refreshDatabase(): void
    {
        $this->beforeRefreshingDatabase();

        if (! RefreshDatabaseState::$migrated) {
            $this->migrateDatabases();

            // Re-run landlord migrations after migrate:fresh wiped them
            $this->artisan('migrate', [
                '--path' => 'database/migrations/landlord',
                '--database' => 'landlord',
                '--force' => true,
            ]);

            $this->app[Kernel::class]->setArtisan(null);

            RefreshDatabaseState::$migrated = true;
        }

        $this->beginDatabaseTransaction();

        $this->afterRefreshingDatabase();
    }

    /**
     * Run migrate:fresh on the default connection.
     */
    protected function migrateDatabases(): void
    {
        /** @var TestCase $this */
        $this->artisan('migrate:fresh', $this->migrateFreshUsing());
    }

    /**
     * Begin a database transaction on all configured connections.
     */
    protected function beginDatabaseTransaction(): void
    {
        $database = $this->app->make('db');

        $connections = $this->connectionsToTransact();

        $this->app->instance('db.transactions', $transactionsManager = new DatabaseTransactionsManager($connections));

        foreach ($connections as $name) {
            $connection = $database->connection($name);

            $connection->setTransactionManager($transactionsManager);

            $dispatcher = $connection->getEventDispatcher();

            $connection->unsetEventDispatcher();
            $connection->beginTransaction();
            $connection->setEventDispatcher($dispatcher);
        }

        $this->beforeApplicationDestroyed(function () use ($database) {
            foreach ($this->connectionsToTransact() as $name) {
                $connection = $database->connection($name);
                $dispatcher = $connection->getEventDispatcher();

                $connection->unsetEventDispatcher();

                if ($connection->getPdo() && ! $connection->getPdo()->inTransaction()) {
                    RefreshDatabaseState::$migrated = false;
                }

                $connection->rollBack();
                $connection->setEventDispatcher($dispatcher);
                $connection->disconnect();
            }
        });
    }

    /**
     * Perform any work that should take place before the database has started refreshing.
     */
    protected function beforeRefreshingDatabase(): void
    {
        // Hook for subclasses
    }

    /**
     * Perform any work that should take place once the database has finished refreshing.
     */
    protected function afterRefreshingDatabase(): void
    {
        // Hook for subclasses
    }

    /**
     * The parameters that should be used when running "migrate:fresh".
     */
    protected function migrateFreshUsing(): array
    {
        $seeder = $this->seederValue();

        return array_merge(
            [
                '--drop-views' => $this->shouldDropViews(),
                '--drop-types' => $this->shouldDropTypes(),
            ],
            $seeder !== null ? ['--seeder' => $seeder] : ['--seed' => $this->shouldSeed()]
        );
    }

    protected function shouldDropViews(): bool
    {
        return property_exists($this, 'dropViews') ? $this->dropViews : false;
    }

    protected function shouldDropTypes(): bool
    {
        return property_exists($this, 'dropTypes') ? $this->dropTypes : false;
    }

    protected function shouldSeed(): bool
    {
        $class = new ReflectionClass($this);

        do {
            if ($class->getAttributes(Seed::class) !== []) {
                return true;
            }
        } while ($class = $class->getParentClass());

        return property_exists($this, 'seed') ? $this->seed : false;
    }

    protected function seederValue(): mixed
    {
        $class = new ReflectionClass($this);

        do {
            $seeder = $class->getAttributes(Seeder::class);

            if (count($seeder) > 0) {
                return $seeder[0]->newInstance()->class;
            }
        } while ($class = $class->getParentClass());

        return property_exists($this, 'seeder') ? $this->seeder : null;
    }

    /**
     * Determine if any of the connections transacting is using in-memory databases.
     */
    protected function usingInMemoryDatabases(): bool
    {
        foreach ($this->connectionsToTransact() as $name) {
            if ($this->usingInMemoryDatabase($name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if a given database connection is an in-memory database.
     */
    protected function usingInMemoryDatabase(?string $name = null): bool
    {
        if (is_null($name)) {
            $name = config('database.default');
        }

        return config("database.connections.{$name}.database") === ':memory:';
    }
}

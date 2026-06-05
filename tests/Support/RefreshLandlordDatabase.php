<?php

namespace Tests\Support;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Connection;
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
     *
     * When landlord and default point to the same physical database,
     * we share the PDO so both connections participate in the same
     * transaction. This prevents the visibility problem where an INSERT
     * on one connection is invisible to a SELECT on the other.
     */
    protected function beginDatabaseTransaction(): void
    {
        $database = $this->app->make('db');

        $connections = $this->connectionsToTransact();

        $this->app->instance('db.transactions', $transactionsManager = new DatabaseTransactionsManager($connections));

        $defaultConnection = $database->connection(null);

        foreach ($connections as $name) {
            $connection = $database->connection($name);

            // If landlord shares the same physical DB as default,
            // copy the PDO so both use the same transaction
            $sharesPdo = false;
            if ($name === 'landlord' && $this->sharesPhysicalDatabase($defaultConnection, $connection)) {
                $this->copyPdo($connection, $defaultConnection);
                $sharesPdo = true;
            }

            $connection->setTransactionManager($transactionsManager);

            $dispatcher = $connection->getEventDispatcher();

            $connection->unsetEventDispatcher();

            // Only begin a new transaction if the connection has its own PDO.
            // If landlord shares default's PDO, it's already in a transaction.
            if (! $sharesPdo || ! $connection->getPdo()->inTransaction()) {
                $connection->beginTransaction();
            }

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
     * Determine if two connections point to the same physical database.
     */
    protected function sharesPhysicalDatabase(Connection $a, Connection $b): bool
    {
        $aName = $a->getName();
        $bName = $b->getName();

        $aConfig = config("database.connections.{$aName}");
        $bConfig = config("database.connections.{$bName}");

        if (! is_array($aConfig) || ! is_array($bConfig)) {
            return false;
        }

        return ($aConfig['host'] ?? null) === ($bConfig['host'] ?? null)
            && ($aConfig['port'] ?? null) === ($bConfig['port'] ?? null)
            && ($aConfig['database'] ?? null) === ($bConfig['database'] ?? null);
    }

    /**
     * Copy the PDO from the source connection to the target connection
     * using reflection. This allows both connections to share the same
     * underlying database session and participate in one transaction.
     */
    protected function copyPdo(Connection $target, Connection $source): void
    {
        $reflection = new ReflectionClass($target);

        if (! $reflection->hasProperty('pdo')) {
            return;
        }

        $pdoProperty = $reflection->getProperty('pdo');
        $pdoProperty->setAccessible(true);
        $pdoProperty->setValue($target, $source->getPdo());
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

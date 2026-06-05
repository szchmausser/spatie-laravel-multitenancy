<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\Attributes\Seed;
use Illuminate\Foundation\Testing\Attributes\Seeder;
use Illuminate\Foundation\Testing\DatabaseTransactionsManager;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;
use ReflectionClass;

abstract class TestCase extends BaseTestCase
{
    /**
     * The connections that should be wrapped in a database transaction.
     *
     * @var array<int, string|null>
     */
    protected array $connectionsToTransact = [null, 'landlord'];

    /**
     * Setup the test environment.
     *
     * Calls refreshDatabase() to handle both default and landlord connections
     * since they share the same physical database but have separate migration paths.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->refreshDatabase();
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }

    /**
     * Refresh the database for testing, handling both default and landlord connections
     * since they share the same physical database but use different migration paths.
     */
    protected function refreshDatabase(): void
    {
        if (! RefreshDatabaseState::$migrated) {
            $this->artisan('migrate:fresh', $this->migrateFreshUsing());

            // Landlord migrations are in a separate path on the same database.
            // Re-run them after migrate:fresh wipes everything.
            $this->artisan('migrate', [
                '--path' => 'database/migrations/landlord',
                '--database' => 'landlord',
                '--force' => true,
            ]);

            $this->app[Kernel::class]->setArtisan(null);

            RefreshDatabaseState::$migrated = true;
        }

        $this->beginDatabaseTransaction();
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

        $connections = $this->connectionsToTransact;

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
            foreach ($this->connectionsToTransact as $name) {
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
        $aConfig = config("database.connections.{$a->getName()}");
        $bConfig = config("database.connections.{$b->getName()}");

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
     * The parameters that should be used when running "migrate:fresh".
     */
    protected function migrateFreshUsing(): array
    {
        $seeder = $this->resolveSeeder();

        return array_merge(
            [
                '--drop-views' => property_exists($this, 'dropViews') ? $this->dropViews : false,
                '--drop-types' => property_exists($this, 'dropTypes') ? $this->dropTypes : false,
            ],
            $seeder !== null ? ['--seeder' => $seeder] : ['--seed' => $this->resolveShouldSeed()]
        );
    }

    protected function resolveShouldSeed(): bool
    {
        $class = new ReflectionClass($this);

        do {
            if ($class->getAttributes(Seed::class) !== []) {
                return true;
            }
        } while ($class = $class->getParentClass());

        return property_exists($this, 'seed') ? $this->seed : false;
    }

    protected function resolveSeeder(): mixed
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
}

<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\Cycle;

use Cycle\Database\Config\DatabaseConfig;
use Cycle\Database\Config\MySQL\DsnConnectionConfig as MySqlDsn;
use Cycle\Database\Config\MySQLDriverConfig;
use Cycle\Database\Config\Postgres\DsnConnectionConfig as PostgresDsn;
use Cycle\Database\Config\PostgresDriverConfig;
use Cycle\Database\Config\SQLite\DsnConnectionConfig as SqliteDsn;
use Cycle\Database\Config\SQLiteDriverConfig;
use Cycle\Database\DatabaseManager;
use Cycle\ORM\Factory;
use Cycle\ORM\ORM;
use Cycle\ORM\Schema;
use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Adapter\Database\Dialect\SqlDialectFactory;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\OrmSpike\Cycle\Connection\FileQueryLog;
use Shopware\Core\Framework\OrmSpike\Cycle\Connection\SharedMySQLDriver;
use Shopware\Core\Framework\OrmSpike\Cycle\Connection\SharedPostgresDriver;
use Shopware\Core\Framework\OrmSpike\Cycle\Connection\SharedSQLiteDriver;
use Shopware\Core\Framework\OrmSpike\Harness\StatementCounter;

/**
 * Builds the Cycle `DatabaseManager` and `ORM` on top of the PDO of Shopware's DBAL connection (no second connection).
 *
 * @internal
 */
#[Package('framework')]
final class CycleBootstrap
{
    public static function database(Connection $connection, StatementCounter $counter, ?string $sqlLog = null): DatabaseManager
    {
        $pdo = $connection->getNativeConnection();
        if (!$pdo instanceof \PDO) {
            throw new \RuntimeException('The DBAL connection has no PDO behind it: ' . get_debug_type($pdo));
        }

        // The DSN is never used, the drivers get the PDO of the DBAL connection ({@see Connection\SharedPdo}).
        $config = match (SqlDialectFactory::forConnection($connection)->getName()) {
            'postgresql' => new PostgresDriverConfig(connection: new PostgresDsn('pgsql:host=unused'), driver: SharedPostgresDriver::class),
            'sqlite' => new SQLiteDriverConfig(connection: new SqliteDsn('sqlite::memory:'), driver: SharedSQLiteDriver::class),
            default => new MySQLDriverConfig(connection: new MySqlDsn('mysql:host=unused'), driver: SharedMySQLDriver::class),
        };

        $manager = new DatabaseManager(new DatabaseConfig([
            'default' => 'default',
            'databases' => ['default' => ['connection' => 'shopware']],
            'connections' => ['shopware' => $config],
        ]));

        $driver = $manager->driver('shopware');
        \assert($driver instanceof SharedMySQLDriver || $driver instanceof SharedPostgresDriver || $driver instanceof SharedSQLiteDriver);
        $driver->share($pdo, $counter);
        if ($sqlLog !== null) {
            $driver->setLogger(new FileQueryLog($sqlLog));
        }

        return $manager;
    }

    /**
     * @param array<string, array<int, mixed>>|null $schema
     */
    public static function orm(DatabaseManager $manager, ?array $schema = null): ORM
    {
        return new ORM(new Factory($manager), new Schema($schema ?? SchemaFactory::build()));
    }
}

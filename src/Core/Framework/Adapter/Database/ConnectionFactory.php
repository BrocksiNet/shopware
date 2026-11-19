<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Adapter\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Shopware\Core\DevOps\Environment\EnvironmentHelper;
use Shopware\Core\Framework\Log\Package;

/**
 * Picks the platform specific connection factory from the scheme of DATABASE_URL.
 *
 * @internal
 */
#[Package('framework')]
class ConnectionFactory
{
    public const DRIVER_MYSQL = 'pdo_mysql';
    public const DRIVER_POSTGRESQL = 'pdo_pgsql';
    public const DRIVER_SQLITE = 'pdo_sqlite';

    private const POSTGRESQL_SCHEMES = ['pgsql', 'postgres', 'postgresql', 'pdo-pgsql'];

    /**
     * @param array<Middleware> $middlewares
     */
    public static function create(array $middlewares = []): Connection
    {
        if (self::getDriver() === self::DRIVER_POSTGRESQL) {
            return PostgreSQLFactory::create($middlewares);
        }

        if (self::getDriver() === self::DRIVER_SQLITE) {
            return SqliteFactory::create($middlewares);
        }

        return MySQLFactory::create($middlewares);
    }

    public static function createMigrationConnection(Connection $connection): Connection
    {
        if ($connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            return PostgreSQLFactory::createMigrationConnection($connection);
        }

        if ($connection->getDatabasePlatform() instanceof SQLitePlatform) {
            return SqliteFactory::createMigrationConnection($connection);
        }

        return MySQLFactory::createMigrationConnection($connection);
    }

    /**
     * @return self::DRIVER_*
     */
    public static function getDriver(): string
    {
        $url = (string) EnvironmentHelper::getVariable('DATABASE_URL', getenv('DATABASE_URL'));
        $scheme = strtolower((string) strstr($url, ':', true));

        if ($scheme === 'sqlite') {
            return self::DRIVER_SQLITE;
        }

        return \in_array($scheme, self::POSTGRESQL_SCHEMES, true) ? self::DRIVER_POSTGRESQL : self::DRIVER_MYSQL;
    }
}

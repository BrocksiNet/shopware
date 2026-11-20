<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Adapter\Database\Dialect;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Shopware\Core\Framework\Adapter\Database\ConnectionFactory;
use Shopware\Core\Framework\Adapter\Database\Sqlite\SqliteDialect;
use Shopware\Core\Framework\Log\Package;

/**
 * Picks the dialect by the platform of the connection.
 *
 * @internal
 */
#[Package('framework')]
class SqlDialectFactory
{
    private static ?SqlDialect $default = null;

    public static function forConnection(Connection $connection): SqlDialect
    {
        $platform = $connection->getDatabasePlatform();

        if ($platform instanceof PostgreSQLPlatform) {
            return new PostgreSqlDialect($connection);
        }

        if ($platform instanceof SQLitePlatform) {
            return new SqliteDialect($connection);
        }

        if ($platform instanceof MariaDBPlatform) {
            return new MariaDbDialect();
        }

        return new MySqlDialect();
    }

    /**
     * For the static call sites without a service (`EntityDefinitionQueryHelper::escape()`), selected by `DATABASE_URL`.
     * Only for identifier quoting and expressions that need no connection: the PostgreSQL and SQLite instances have none.
     */
    public static function getDefault(): SqlDialect
    {
        if (self::$default !== null) {
            return self::$default;
        }

        return self::$default = match (ConnectionFactory::getDriver()) {
            ConnectionFactory::DRIVER_POSTGRESQL => new PostgreSqlDialect(),
            ConnectionFactory::DRIVER_SQLITE => new SqliteDialect(),
            default => new MySqlDialect(),
        };
    }

    public static function resetDefault(): void
    {
        self::$default = null;
    }
}

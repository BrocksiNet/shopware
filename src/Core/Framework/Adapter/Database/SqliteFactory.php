<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Adapter\Database;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Shopware\Core\DevOps\Environment\EnvironmentHelper;
use Shopware\Core\Framework\Adapter\Database\Sqlite\SessionMiddleware;
use Shopware\Core\Framework\Adapter\Database\Sqlite\SqlTranslationMiddleware;
use Shopware\Core\Framework\Log\Package;

/**
 * SQLite counterpart of MySQLFactory, selected by DATABASE_URL=sqlite:///path/to/file.db (spike).
 *
 * @internal
 */
#[Package('framework')]
class SqliteFactory
{
    /**
     * @param array<Middleware> $middlewares
     */
    public static function create(array $middlewares = []): Connection
    {
        $config = (new Configuration())
            ->setMiddlewares([new SessionMiddleware(), new SqlTranslationMiddleware(), ...$middlewares]);

        return self::mapTypes(DriverManager::getConnection(self::getConnectionParameters(), $config));
    }

    public static function createMigrationConnection(Connection $connection): Connection
    {
        return self::mapTypes(DriverManager::getConnection(self::getConnectionParameters(), $connection->getConfiguration()));
    }

    /**
     * The SQLite platform does not know the MySQL column types the schema manager reads back
     */
    private static function mapTypes(Connection $connection): Connection
    {
        $platform = $connection->getDatabasePlatform();
        foreach (['binary' => 'binary', 'varbinary' => 'binary', 'tinyint' => 'smallint', 'mediumint' => 'integer', 'longtext' => 'text', 'mediumtext' => 'text', 'tinytext' => 'text', 'mediumblob' => 'blob', 'longblob' => 'blob', 'datetime' => 'datetime', 'json' => 'json', 'year' => 'integer', 'enum' => 'string'] as $db => $doctrine) {
            $platform->registerDoctrineTypeMapping($db, $doctrine);
        }

        return $connection;
    }

    /**
     * @return array<string, mixed>
     */
    private static function getConnectionParameters(): array
    {
        $url = (string) EnvironmentHelper::getVariable('DATABASE_URL', getenv('DATABASE_URL'));
        $parameters = (new DsnParser(['sqlite' => 'pdo_sqlite']))->parse($url);
        $parameters['driver'] = 'pdo_sqlite';
        $parameters['driverOptions'] = [\PDO::ATTR_STRINGIFY_FETCHES => true] + ($parameters['driverOptions'] ?? []);

        return $parameters;
    }
}

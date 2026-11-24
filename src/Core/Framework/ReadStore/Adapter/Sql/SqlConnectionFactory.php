<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Adapter\Sql;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\ReadStore\ReadStoreException;

/**
 * A plain DBAL connection for a read store database. It is deliberately not the Shopware connection: the SQL of the
 * adapter is native to the engine and must not pass the middlewares of the main connection (e.g. the PostgreSQL translator).
 *
 * @internal
 */
#[Package('framework')]
final class SqlConnectionFactory
{
    public static function create(string $url): Connection
    {
        $params = (new DsnParser([
            'mysql' => 'pdo_mysql',
            'pgsql' => 'pdo_pgsql',
            'sqlite' => 'pdo_sqlite',
        ]))->parse($url);

        if (($params['driver'] ?? '') === 'pdo_mysql') {
            $params['charset'] = 'utf8mb4';
        }

        return DriverManager::getConnection($params);
    }

    public static function dialect(string $name): JsonDialect
    {
        return match ($name) {
            'mysql' => new MySqlJsonDialect(),
            'mariadb' => new MariaDbJsonDialect(),
            'pgsql' => new PostgreSqlJsonDialect(),
            'sqlite' => new SqliteJsonDialect(),
            default => throw ReadStoreException::invalidCriteria('unknown SQL dialect ' . $name),
        };
    }
}

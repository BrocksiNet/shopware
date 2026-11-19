<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Adapter\Database\Sqlite;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Shopware\Core\Framework\Log\Package;

/**
 * pdo_sqlite has no init command, so the pragmas are set and the PHP implemented MySQL functions are registered right after connecting.
 *
 * @internal
 */
#[Package('framework')]
class SessionMiddleware implements Middleware
{
    public function wrap(Driver $driver): Driver
    {
        return new class($driver) extends AbstractDriverMiddleware {
            public function connect(#[\SensitiveParameter] array $params): Connection
            {
                $connection = parent::connect($params);
                $connection->exec('PRAGMA foreign_keys = ON');
                $connection->exec('PRAGMA journal_mode = WAL');
                $connection->exec('PRAGMA busy_timeout = 5000');

                $native = $connection->getNativeConnection();
                if ($native instanceof \PDO) {
                    SqliteFunctions::register($native);
                }

                return $connection;
            }
        };
    }
}

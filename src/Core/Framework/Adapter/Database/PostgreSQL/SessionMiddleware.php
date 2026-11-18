<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Adapter\Database\PostgreSQL;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Shopware\Core\Framework\Log\Package;

/**
 * pdo_pgsql has no init command, so the session variables are set right after connecting.
 * This is the PostgreSQL counterpart of the MySQL init command in MySQLFactory.
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
                $connection->exec('SET TIME ZONE \'UTC\'');

                return $connection;
            }
        };
    }
}

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
                // `information_schema.columns` / `.tables` are rewritten to these views by the translation middleware
                $connection->exec('CREATE TEMP VIEW IF NOT EXISTS sw_is_columns AS SELECT \'main\' AS TABLE_SCHEMA, m.name AS TABLE_NAME, p.name AS COLUMN_NAME, p.cid + 1 AS ORDINAL_POSITION,'
                    . ' lower(p.type) AS COLUMN_TYPE, lower(CASE WHEN instr(p.type, \'(\') > 0 THEN substr(p.type, 1, instr(p.type, \'(\') - 1) ELSE p.type END) AS DATA_TYPE,'
                    . ' CASE WHEN p.`notnull` = 1 THEN \'NO\' ELSE \'YES\' END AS IS_NULLABLE, p.dflt_value AS COLUMN_DEFAULT,'
                    . ' CASE p.hidden WHEN 2 THEN \'STORED GENERATED\' WHEN 3 THEN \'STORED GENERATED\' ELSE \'\' END AS EXTRA'
                    . ' FROM sqlite_master m, pragma_table_xinfo(m.name) p WHERE m.type = \'table\'');
                $connection->exec('CREATE TEMP VIEW IF NOT EXISTS sw_is_tables AS SELECT \'main\' AS TABLE_SCHEMA, name AS TABLE_NAME, \'BASE TABLE\' AS TABLE_TYPE FROM sqlite_master WHERE type = \'table\'');

                $native = $connection->getNativeConnection();
                if ($native instanceof \PDO) {
                    SqliteFunctions::register($native);
                }

                return $connection;
            }
        };
    }
}

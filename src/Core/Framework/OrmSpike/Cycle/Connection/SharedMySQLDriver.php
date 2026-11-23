<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\Cycle\Connection;

use Cycle\Database\Driver\MySQL\MySQLDriver;
use Shopware\Core\Framework\Log\Package;

/**
 * @internal
 */
#[Package('framework')]
final class SharedMySQLDriver extends MySQLDriver
{
    use SharedPdo;
}

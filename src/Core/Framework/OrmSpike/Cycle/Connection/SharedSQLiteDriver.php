<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\Cycle\Connection;

use Cycle\Database\Driver\SQLite\SQLiteDriver;
use Shopware\Core\Framework\Log\Package;

/**
 * @internal
 */
#[Package('framework')]
final class SharedSQLiteDriver extends SQLiteDriver
{
    use SharedPdo;
}

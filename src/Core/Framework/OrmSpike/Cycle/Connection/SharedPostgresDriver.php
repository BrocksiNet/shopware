<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\Cycle\Connection;

use Cycle\Database\Driver\Postgres\PostgresDriver;
use Shopware\Core\Framework\Log\Package;

/**
 * @internal
 */
#[Package('framework')]
final class SharedPostgresDriver extends PostgresDriver
{
    use SharedPdo;
}

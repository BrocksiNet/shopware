<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\DalV2\Capture;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Shopware\Core\Framework\Log\Package;

/**
 * Driver connection wrapper that logs statements and their parameters (set by reflection, like the profile connection).
 *
 * @internal
 */
#[Package('framework')]
final class SqlLogConnection extends AbstractConnectionMiddleware
{
    public function __construct(Connection $inner)
    {
        parent::__construct($inner);
    }

    public function prepare(string $sql): Statement
    {
        return new SqlLogStatement(parent::prepare($sql), $sql);
    }

    public function query(string $sql): Result
    {
        $start = hrtime(true);

        try {
            return parent::query($sql);
        } finally {
            SqlLog::statement($sql, [], (hrtime(true) - $start) / 1e6);
        }
    }

    public function exec(string $sql): int|string
    {
        $start = hrtime(true);

        try {
            return parent::exec($sql);
        } finally {
            SqlLog::statement($sql, [], (hrtime(true) - $start) / 1e6);
        }
    }
}

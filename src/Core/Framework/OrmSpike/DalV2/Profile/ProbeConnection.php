<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\DalV2\Profile;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Shopware\Core\Framework\Log\Package;

/**
 * Wraps the driver connection (set by reflection in the profile mode only) and books execute and fetch time to `sql`.
 *
 * @internal
 */
#[Package('framework')]
final class ProbeConnection extends AbstractConnectionMiddleware
{
    public function __construct(Connection $inner)
    {
        parent::__construct($inner);
    }

    public function prepare(string $sql): Statement
    {
        return new ProbeStatement(parent::prepare($sql), $sql);
    }

    public function query(string $sql): Result
    {
        Probe::enter('sql');
        $start = hrtime(true);

        try {
            return new ProbeResult(parent::query($sql));
        } finally {
            Probe::statement($sql, hrtime(true) - $start);
            Probe::leave();
        }
    }

    public function exec(string $sql): int|string
    {
        Probe::enter('sql');
        $start = hrtime(true);

        try {
            return parent::exec($sql);
        } finally {
            Probe::statement($sql, hrtime(true) - $start);
            Probe::leave();
        }
    }
}

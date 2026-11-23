<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\DalV2\Profile;

use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Shopware\Core\Framework\Log\Package;

/**
 * @internal
 */
#[Package('framework')]
final class ProbeStatement extends AbstractStatementMiddleware
{
    public function __construct(Statement $inner, private readonly string $sql)
    {
        parent::__construct($inner);
    }

    public function execute(): Result
    {
        Probe::enter('sql');
        $start = hrtime(true);

        try {
            return new ProbeResult(parent::execute());
        } finally {
            Probe::statement($this->sql, hrtime(true) - $start);
            Probe::leave();
        }
    }
}

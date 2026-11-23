<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\Cycle\Connection;

use Cycle\Database\StatementInterface;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\OrmSpike\Harness\StatementCounter;

/**
 * Lets a Cycle driver run on the PDO that sits behind Shopware's DBAL connection instead of opening its own.
 * `Driver::createPDO()` is the only place where Cycle opens a connection, so the driver subclass returns the shared object.
 * Every statement is reported to the harness counter.
 *
 * @internal
 */
#[Package('framework')]
trait SharedPdo
{
    private ?StatementCounter $counter = null;

    public function share(\PDO $pdo, StatementCounter $counter): void
    {
        $this->pdo = $pdo;
        $this->counter = $counter;
    }

    protected function createPDO(): \PDO
    {
        return $this->pdo ?? throw new \LogicException('Call share() with the PDO of the DBAL connection first');
    }

    protected function statement(string $query, iterable $parameters = [], bool $retry = true): StatementInterface
    {
        $this->counter?->add();

        return parent::statement($query, $parameters, $retry);
    }
}

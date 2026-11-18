<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Adapter\Database\PostgreSQL;

use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Shopware\Core\Framework\Log\Package;

/**
 * @internal
 */
#[Package('framework')]
class ColumnCaseStatement extends AbstractStatementMiddleware
{
    /**
     * @param array<string, string> $caseMap
     */
    public function __construct(Statement $statement, private readonly array $caseMap)
    {
        parent::__construct($statement);
    }

    public function execute(): Result
    {
        $result = parent::execute();

        return $this->caseMap === [] ? $result : new ColumnCaseResult($result, $this->caseMap);
    }
}

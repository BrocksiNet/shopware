<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Adapter\Database\PostgreSQL;

use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Shopware\Core\Framework\Log\Package;

/**
 * Runs a prepared DDL statement inside a savepoint, the same as SqlTranslationMiddleware does for exec().
 *
 * @internal
 */
#[Package('framework')]
class DdlSavepointStatement extends AbstractStatementMiddleware
{
    /**
     * @param \Closure(\Closure(): Result): Result $inSavepoint
     */
    public function __construct(Statement $statement, private readonly \Closure $inSavepoint)
    {
        parent::__construct($statement);
    }

    public function execute(): Result
    {
        return ($this->inSavepoint)(fn (): Result => parent::execute());
    }
}

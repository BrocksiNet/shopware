<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Adapter\Database\PostgreSQL;

use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\ParameterType;
use Shopware\Core\Framework\Log\Package;

/**
 * @internal
 */
#[Package('framework')]
class PlanCacheStatement extends AbstractStatementMiddleware
{
    public function __construct(
        Statement $statement,
        private readonly \Closure $forget,
    ) {
        parent::__construct($statement);
    }

    public function bindValue(int|string $param, mixed $value, ParameterType $type): void
    {
        // client side quoting writes a bool as 't' or 'f', which smallint and other non boolean parameter types reject
        if (\is_bool($value) || $type === ParameterType::BOOLEAN) {
            parent::bindValue($param, $value === null ? null : (string) (int) $value, $value === null ? ParameterType::NULL : ParameterType::STRING);

            return;
        }

        parent::bindValue($param, $value, $type);
    }

    public function execute(): Result
    {
        try {
            return parent::execute();
        } catch (\Throwable $e) {
            // `cached plan must not change result type` after a schema change, `prepared statement does not exist` after a connection reset
            if (str_contains($e->getMessage(), 'cached plan must not change result type') || str_contains($e->getMessage(), 'does not exist')) {
                ($this->forget)();
            }

            throw $e;
        }
    }
}

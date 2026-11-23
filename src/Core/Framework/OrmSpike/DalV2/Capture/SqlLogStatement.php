<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\DalV2\Capture;

use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\ParameterType;
use Shopware\Core\Framework\Log\Package;

/**
 * @internal
 */
#[Package('framework')]
final class SqlLogStatement extends AbstractStatementMiddleware
{
    /**
     * @var array<int|string, array{mixed, string}>
     */
    private array $params = [];

    public function __construct(Statement $inner, private readonly string $sql)
    {
        parent::__construct($inner);
    }

    public function bindValue(int|string $param, mixed $value, ParameterType $type): void
    {
        $this->params[$param] = [$value, $type->name];
        parent::bindValue($param, $value, $type);
    }

    public function execute(): Result
    {
        $start = hrtime(true);

        try {
            return parent::execute();
        } finally {
            SqlLog::statement($this->sql, $this->params, (hrtime(true) - $start) / 1e6);
        }
    }
}

<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Adapter\Database\Sqlite;

use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Result as DriverResult;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\ParameterType;
use Shopware\Core\Framework\Log\Package;

/**
 * pdo_sqlite prepares one statement only, a rewritten DDL statement can expand to several (CREATE TABLE plus its indexes).
 *
 * @internal
 */
#[Package('framework')]
class MultiStatement implements Statement
{
    /**
     * @param list<string|\Closure> $statements
     */
    public function __construct(
        private readonly object $connection,
        private readonly array $statements
    ) {
    }

    public function bindValue(int|string $param, mixed $value, ParameterType $type): void
    {
    }

    public function execute(): Result
    {
        $affected = 0;
        foreach ($this->statements as $statement) {
            $affected = $this->connection->execRaw($statement);
        }

        return new class($affected) implements DriverResult {
            public function __construct(private readonly int|string $affected)
            {
            }

            public function fetchNumeric(): array|false
            {
                return false;
            }

            public function fetchAssociative(): array|false
            {
                return false;
            }

            public function fetchOne(): mixed
            {
                return false;
            }

            public function fetchAllNumeric(): array
            {
                return [];
            }

            public function fetchAllAssociative(): array
            {
                return [];
            }

            public function fetchFirstColumn(): array
            {
                return [];
            }

            public function rowCount(): int|string
            {
                return $this->affected;
            }

            public function columnCount(): int
            {
                return 0;
            }

            public function free(): void
            {
            }
        };
    }
}

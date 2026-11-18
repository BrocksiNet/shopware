<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Adapter\Database\PostgreSQL;

use Doctrine\DBAL\Driver\Middleware\AbstractResultMiddleware;
use Doctrine\DBAL\Driver\Result;
use Shopware\Core\Framework\Log\Package;

/**
 * Restores the spelling of column names that PostgreSQL folded to lower case.
 *
 * @internal
 */
#[Package('framework')]
class ColumnCaseResult extends AbstractResultMiddleware
{
    /**
     * @param array<string, string> $caseMap lower cased name => name as written in the statement
     */
    public function __construct(Result $result, private readonly array $caseMap)
    {
        parent::__construct($result);
    }

    public function fetchAssociative(): array|false
    {
        $row = parent::fetchAssociative();

        return $row === false ? false : $this->restore($row);
    }

    public function fetchAllAssociative(): array
    {
        return array_map($this->restore(...), parent::fetchAllAssociative());
    }

    public function getColumnName(int $index): string
    {
        $name = parent::getColumnName($index);

        return $this->caseMap[$name] ?? $name;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function restore(array $row): array
    {
        $restored = [];
        foreach ($row as $name => $value) {
            $restored[$this->caseMap[$name] ?? $name] = $value;
        }

        return $restored;
    }
}

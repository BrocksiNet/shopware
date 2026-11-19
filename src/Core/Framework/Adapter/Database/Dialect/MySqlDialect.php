<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Adapter\Database\Dialect;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Shopware\Core\Framework\Log\Package;

/**
 * @internal
 */
#[Package('framework')]
class MySqlDialect implements SqlDialect
{
    public function getName(): string
    {
        return self::MYSQL;
    }

    public function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    public function upsert(string $table, array $columns, string $valuesSql, array $conflictColumns = [], array $updateColumns = [], bool $ignoreConflicts = false): string
    {
        $sql = \sprintf(
            'INSERT %sINTO %s (%s) VALUES %s',
            $ignoreConflicts ? 'IGNORE ' : '',
            $this->quoteIdentifier($table),
            implode(', ', array_map($this->quoteIdentifier(...), $columns)),
            $valuesSql
        );

        if ($updateColumns === []) {
            return $sql;
        }

        $updates = [];
        foreach ($updateColumns as $column) {
            $updates[] = \sprintf('%s = VALUES(%s)', $this->quoteIdentifier($column), $this->quoteIdentifier($column));
        }

        return $sql . ' ON DUPLICATE KEY UPDATE ' . implode(', ', $updates);
    }

    public function jsonExtract(string $jsonColumnSql, string $path): string
    {
        return \sprintf('JSON_EXTRACT(%s, %s)', $jsonColumnSql, $this->stringLiteral($path));
    }

    public function jsonExtractText(string $jsonColumnSql, string $path): string
    {
        return \sprintf('JSON_UNQUOTE(%s)', $this->jsonExtract($jsonColumnSql, $path));
    }

    public function jsonExtractNumber(string $jsonColumnSql, string $path): string
    {
        return $this->jsonExtractText($jsonColumnSql, $path);
    }

    public function jsonSet(string $jsonColumnSql, string $path, string $valueSql): string
    {
        return \sprintf('JSON_SET(IFNULL(%s, "{}"), %s, %s)', $jsonColumnSql, $this->stringLiteral($path), $valueSql);
    }

    public function jsonValue(string $jsonColumnSql, string $path, string $type = self::JSON_TYPE_STRING): string
    {
        $extract = $this->jsonExtract($jsonColumnSql, $path);

        // Values extracted from json have json types, a json null has to become an sql null
        return \sprintf('IF(JSON_TYPE(%s) != "NULL", %s, NULL)', $extract, $this->convertJsonValue($extract, $type));
    }

    public function jsonBind(string $placeholderSql, string $type): string
    {
        return match ($type) {
            self::JSON_BIND_FLOAT => $placeholderSql . ' + 0.0',
            // does the same thing as CAST(?, json) but works on mariadb
            self::JSON_BIND_OBJECT => \sprintf('JSON_MERGE("{}", %s)', $placeholderSql),
            self::JSON_BIND_ARRAY => \sprintf('JSON_MERGE("[]", %s)', $placeholderSql),
            default => $placeholderSql,
        };
    }

    public function idToText(string $columnSql): string
    {
        return \sprintf('LOWER(HEX(%s))', $columnSql);
    }

    public function idParameterType(): ParameterType
    {
        return ParameterType::BINARY;
    }

    public function idListParameterType(): ArrayParameterType
    {
        return ArrayParameterType::BINARY;
    }

    public function groupConcat(string $expressionSql, string $separator = ',', ?string $orderBySql = null): string
    {
        return \sprintf(
            'GROUP_CONCAT(%s%s SEPARATOR %s)',
            $expressionSql,
            $orderBySql === null ? '' : ' ORDER BY ' . $orderBySql,
            $this->stringLiteral($separator)
        );
    }

    public function caseInsensitive(string $columnSql): string
    {
        return $columnSql;
    }

    protected function convertJsonValue(string $extractSql, string $type): string
    {
        $unquoted = \sprintf('JSON_UNQUOTE(%s)', $extractSql);

        return match ($type) {
            self::JSON_TYPE_FLOAT => $unquoted . ' + 0.0',
            self::JSON_TYPE_BOOL => \sprintf('IF(%s != "true" AND %s = 0, 0, 1)', $unquoted, $unquoted),
            self::JSON_TYPE_DATETIME => \sprintf('CAST(%s AS datetime(3))', $unquoted),
            self::JSON_TYPE_DATE => \sprintf('CAST(%s AS DATE)', $unquoted),
            // The CONVERT is required for mariadb support (mysqls JSON_UNQUOTE returns utf8mb4)
            default => \sprintf('CONVERT(%s USING "utf8mb4") COLLATE utf8mb4_unicode_ci', $unquoted),
        };
    }

    protected function stringLiteral(string $value): string
    {
        return '\'' . str_replace(['\\', '\''], ['\\\\', '\'\''], $value) . '\'';
    }
}

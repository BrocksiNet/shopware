<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Adapter\Database\Dialect;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Shopware\Core\Framework\Log\Package;

/**
 * Generates the SQL fragments that differ between the supported databases.
 * Every method returns SQL text, the caller binds the values.
 * Extend this interface additively only, other implementations (SQLite) build on it.
 *
 * @internal
 */
#[Package('framework')]
interface SqlDialect
{
    public const MYSQL = 'mysql';

    public const MARIADB = 'mariadb';

    public const POSTGRESQL = 'postgresql';

    public const SQLITE = 'sqlite';

    /**
     * One of the platform constants above.
     */
    public function getName(): string;

    /**
     * Quotes a table, column or alias name: backticks on MySQL and MariaDB, double quotes otherwise.
     */
    public function quoteIdentifier(string $identifier): string;

    /**
     * Builds an INSERT statement for the already prepared VALUES list (`(?, ?), (?, ?)`).
     * `$updateColumns` non empty: update these columns from the inserted row when `$conflictColumns` collide
     * (`ON DUPLICATE KEY UPDATE` / `ON CONFLICT (...) DO UPDATE`).
     * `$ignoreConflicts`: skip colliding rows (`INSERT IGNORE` / `ON CONFLICT DO NOTHING`).
     * `$conflictColumns` is ignored by MySQL, which reacts to any unique key.
     *
     * @param list<string> $columns unquoted column names
     * @param list<string> $conflictColumns unquoted column names
     * @param list<string> $updateColumns unquoted column names
     */
    public function upsert(string $table, array $columns, string $valuesSql, array $conflictColumns = [], array $updateColumns = [], bool $ignoreConflicts = false): string;

    /**
     * The JSON value (still JSON typed) at the path, `$.a.b` syntax, `$jsonColumnSql` is an SQL expression.
     */
    public function jsonExtract(string $jsonColumnSql, string $path): string;

    /**
     * The value at the path as text (`JSON_UNQUOTE(JSON_EXTRACT())` / `->>`), SQL NULL for a JSON null.
     */
    public function jsonExtractText(string $jsonColumnSql, string $path): string;

    /**
     * `$valueSql` is a JSON typed SQL expression, `$jsonColumnSql` may be NULL (treated as `{}`).
     */
    public function jsonSet(string $jsonColumnSql, string $path, string $valueSql): string;

    /**
     * Expression that selects the id column as lower case 32 character hex text.
     */
    public function idToText(string $columnSql): string;

    /**
     * Parameter type to bind a single id.
     */
    public function idParameterType(): ParameterType;

    /**
     * Parameter type to bind a list of ids.
     */
    public function idListParameterType(): ArrayParameterType;

    /**
     * `GROUP_CONCAT(expr ORDER BY ... SEPARATOR 'sep')` / `string_agg(expr::text, 'sep' ORDER BY ...)`.
     * `$separator` is a plain string, the dialect quotes it.
     */
    public function groupConcat(string $expressionSql, string $separator = ',', ?string $orderBySql = null): string;
}

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

    public const JSON_TYPE_STRING = 'string';

    public const JSON_TYPE_FLOAT = 'float';

    public const JSON_TYPE_BOOL = 'bool';

    public const JSON_TYPE_DATETIME = 'datetime';

    public const JSON_TYPE_DATE = 'date';

    public const JSON_BIND_STRING = 'string';

    public const JSON_BIND_INT = 'int';

    public const JSON_BIND_FLOAT = 'float';

    public const JSON_BIND_BOOL = 'bool';

    public const JSON_BIND_OBJECT = 'object';

    public const JSON_BIND_ARRAY = 'array';

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
     * The value at the path for arithmetic and numeric comparison (`* factor`, `+ 0.0`, `CAST(... AS DECIMAL)`), SQL NULL if the path is missing or a JSON null.
     * MySQL and MariaDB convert the unquoted text implicitly, PostgreSQL needs the explicit `::numeric`.
     */
    public function jsonExtractNumber(string $jsonColumnSql, string $path): string;

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

    /**
     * Accessor for a value inside a JSON column: the value at `$path` (`$.a.b`, hyphenated keys quoted as `$.a."b-c"`)
     * converted to `$type` (one of the JSON_TYPE_* constants), a JSON null becomes SQL NULL.
     * `$path` is plain text, the dialect turns it into an SQL literal.
     */
    public function jsonValue(string $jsonColumnSql, string $path, string $type = self::JSON_TYPE_STRING): string;

    /**
     * Wraps a bound placeholder (or for `JSON_TYPE_BOOL` the literal `true` / `false`) so it can be used as the value of `jsonSet()`.
     * `$type` is one of the JSON_BIND_* constants.
     */
    public function jsonBind(string $placeholderSql, string $type): string;

    /**
     * A string column in a form that is compared case-insensitively with `=`, `IN` and `LIKE` against placeholders bound
     * with lower-case values (`mb_strtolower()` on the PHP side).
     * MySQL and MariaDB: the column itself (the `utf8mb4_unicode_ci` collation does it).
     * PostgreSQL: the columns have a nondeterministic collation, which hashes and compares per row through ICU and cannot use an index,
     * so the expression is `lower(column) COLLATE ucs_basic`, (`ucs_basic` is written without quotes because the translator reads a double quoted token as a string literal), which can use the expression indexes of the PostgreSQL migration.
     */
    public function caseInsensitive(string $columnSql): string;

    /**
     * Appended to a derived table that computes an expensive expression (for example `lower(column)`), so that the expression is evaluated once per row
     * and not inlined into every reference of the outer query.
     * PostgreSQL: ` OFFSET 0`, an optimization fence for the planner that keeps it from pulling the subquery up.
     * MySQL, MariaDB and SQLite merge the derived table, the result is an empty string and callers keep the plain query.
     */
    public function subqueryFence(): string;
}

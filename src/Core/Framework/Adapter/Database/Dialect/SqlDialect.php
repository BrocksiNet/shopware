<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Adapter\Database\Dialect;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractPlatform;
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
     * Whether the string filters of the DAL (`equals`, `equalsAny`, `contains`, `prefix`, `suffix` on string columns) have to wrap the column in `caseInsensitive()`
     * and bind `caseInsensitiveValue()` to be case-insensitive.
     * MySQL, MariaDB: false (the column collation compares case-insensitive).
     * SQLite: false (the `NOCASE` column collation and `LIKE` are case-insensitive for ASCII, `lower()` would not add anything and would bypass the column indexes).
     * PostgreSQL: true, the filters are case-insensitive but accent-sensitive (`Müller` does not match `muller`), see docs/23-postgresql-api-reads.md of the research repository.
     */
    public function caseInsensitiveStringFilters(): bool;

    /**
     * The sort direction (`ASC` or `DESC`) with the NULL placement that MySQL, MariaDB and SQLite have (NULL is the smallest value: first for `ASC`, last for `DESC`).
     * MySQL, MariaDB, SQLite: the direction unchanged.
     * PostgreSQL: `ASC NULLS FIRST` / `DESC NULLS LAST`; by default NULL is the largest value there.
     * The suffix keeps a btree index from serving the sort (it is built `ASC NULLS LAST`), use it only where the sort is not served by an index (aggregation buckets).
     */
    public function sortDirectionWithMySqlNulls(string $direction): string;

    /**
     * A number extracted from JSON as a double, so that arithmetic on it rounds like MySQL and MariaDB do (`text + 0.0` is a DOUBLE there).
     * MySQL, MariaDB, SQLite: the expression unchanged.
     * PostgreSQL: `CAST(expr AS double precision)`; with `numeric` the product `13.016666666666666 * 1.5` stays exact (19.524999999999999) where the double product is 19.525 and rounds up.
     */
    public function castToFloat(string $numberSql): string;

    /**
     * The columns by which two rows of one multi row upsert collide, or an empty list if the database applies such rows one after the other.
     * PostgreSQL: the given conflict columns or the primary key; `ON CONFLICT DO UPDATE` raises "cannot affect row a second time" when two rows of one statement have the same key.
     * MySQL, MariaDB, SQLite: an empty list.
     *
     * @param list<string> $conflictColumns
     *
     * @return list<string>
     */
    public function upsertCollisionColumns(string $table, array $conflictColumns): array;

    /**
     * The result of a decimal division with the scale that MySQL and MariaDB produce (`div_precision_increment`, 4 digits more than the dividend).
     * MySQL, MariaDB: the expression unchanged.
     * PostgreSQL: `CAST(expr AS NUMERIC(30, scale))`, because a `numeric` division returns at least 16 digits and the DAL returns aggregates as strings.
     * SQLite: the expression unchanged.
     */
    public function decimalDivisionScale(string $expressionSql, int $scale): string;

    /**
     * The value to bind against a column wrapped in `caseInsensitive()`.
     * MySQL and MariaDB: the value unchanged (the collation compares case-insensitive, SQL and parameters stay as before).
     * PostgreSQL and SQLite: the value in lower case (`mb_strtolower()`), because `caseInsensitive()` lowers only the column side.
     */
    public function caseInsensitiveValue(string $value): string;

    /**
     * Appended to a derived table that computes an expensive expression (for example `lower(column)`), so that the expression is evaluated once per row
     * and not inlined into every reference of the outer query.
     * PostgreSQL: ` OFFSET 0`, an optimization fence for the planner that keeps it from pulling the subquery up.
     * MySQL, MariaDB and SQLite merge the derived table, the result is an empty string and callers keep the plain query.
     */
    public function subqueryFence(): string;

    /**
     * A window function expression that numbers the rows of each partition starting at 1, in the order of the surrounding ORDER BY.
     * PostgreSQL and SQLite: `ROW_NUMBER() OVER (PARTITION BY ...)`.
     * MySQL and MariaDB: null, callers keep the session variable counter (`@n`, `@c`) they have always used there.
     */
    public function rowNumberPerPartition(string $partitionSql): ?string;

    /**
     * The DBAL platform that renders schema diffs (`getAlterSchemaSQL`) to the DDL the connection executes, e.g. for custom entity tables.
     * MySQL, MariaDB and SQLite: the connection platform.
     * PostgreSQL: MySQL DDL, the connection translates every DDL statement from MySQL and does not read the native PostgreSQL DDL of the DBAL platform
     * (`BYTEA` for a fixed `BINARY(16)` id column, `TIMESTAMP(0) WITHOUT TIME ZONE`).
     */
    public function schemaDdlPlatform(AbstractPlatform $connectionPlatform): AbstractPlatform;
}

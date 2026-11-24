<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Adapter\Sql;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Log\Package;

/**
 * What differs between the engines: DDL, the JSON access expressions and the collation handling.
 *
 * Every expression method takes the SQL of the JSON column (`$doc`) and a key path, and binds the path through the
 * given binder. A "text" expression is NULL unless the JSON value is a string, "number" unless it is a number and "bool"
 * (1 or 0) unless it is a boolean. A missing key and a JSON null are both NULL, as in the reference evaluator.
 *
 * @internal
 */
#[Package('framework')]
interface JsonDialect
{
    /**
     * `mysql`, `mariadb`, `pgsql` or `sqlite`
     */
    public function name(): string;

    /**
     * Called before every operation, registers per connection things (SQLite functions).
     */
    public function bootstrap(Connection $connection): void;

    /**
     * Objects the engine needs once per database (the PostgreSQL case-insensitive collation).
     */
    public function setupDatabase(Connection $connection): void;

    public function quote(string $identifier): string;

    /**
     * @return list<string>
     */
    public function createMetaTable(string $table): array;

    /**
     * @return list<string>
     */
    public function createDocumentTable(string $table): array;

    /**
     * @return list<string>
     */
    public function createKeywordTable(string $table): array;

    /**
     * Inserts or replaces a document, parameters `:id`, `:doc`, `:active`, `:manufacturer_id` and `:display_group`.
     * The insertion position stays when a document is replaced.
     */
    public function upsertDocument(string $table): string;

    /**
     * Inserts or replaces a meta row, parameters `:name` and `:value`.
     */
    public function upsertMeta(string $table): string;

    /**
     * Expression of the insertion position of a document row, the order of unsorted results.
     */
    public function position(string $alias): string;

    /**
     * `LIMIT ... OFFSET ...` clause, a null limit means all rows
     */
    public function limit(?int $limit, int $offset): string;

    /**
     * Null placement of an ORDER BY term, the reference sorts nulls first when ascending and last when descending.
     */
    public function orderTerm(string $expression, string $direction): string;

    /**
     * @param list<string> $path
     */
    public function kind(string $doc, array $path, Binder $binder): string;

    /**
     * @param list<string> $path
     */
    public function text(string $doc, array $path, Binder $binder): string;

    /**
     * @param list<string> $path
     */
    public function number(string $doc, array $path, Binder $binder): string;

    /**
     * @param list<string> $path
     */
    public function bool(string $doc, array $path, Binder $binder): string;

    /**
     * True when the value is a JSON array, NULL or false otherwise.
     *
     * @param list<string> $path
     */
    public function isArray(string $doc, array $path, Binder $binder): string;

    /**
     * True for an array with at least one element, false for everything else, never NULL.
     *
     * @param list<string> $path
     */
    public function arrayNotEmpty(string $doc, array $path, Binder $binder): string;

    /**
     * True when the JSON array contains the string, false when it does not, NULL when the value is no array.
     *
     * @param list<string> $path
     */
    public function arrayContains(string $doc, array $path, string $value, Binder $binder): string;

    /**
     * Index-friendly positive-context array containment predicate (only used outside NOT and when NULL is not a
     * candidate; may return false instead of NULL for non-arrays). Returns null when no native array index exists.
     *
     * @param list<string> $path
     */
    public function indexedArrayContains(string $doc, array $path, string $value, Binder $binder): ?string;

    /**
     * A row source producing one row per array element, to be appended to a FROM clause that already has a table.
     *
     * @param list<string> $path
     *
     * @return array{from: string, value: string} the FROM fragment (starts with a comma) and the element expression
     */
    public function unnest(string $doc, array $path, Binder $binder): array;

    public function toFloat(string $expression): string;

    /**
     * Equality with the case and accent insensitive rules of the reference (fold).
     */
    public function ciEquals(string $expression, string $value, Binder $binder): string;

    /**
     * @param 'contains'|'prefix' $mode
     */
    public function ciLike(string $expression, string $value, string $mode, Binder $binder): string;

    /**
     * The expression with the collation used for ordering and ranges, the reference uses an ICU collator at primary strength.
     */
    public function ciCollate(string $expression): string;

    /**
     * The expression to group and count distinct values case and accent insensitively.
     */
    public function ciKey(string $expression): string;

    /**
     * Lower case and compare byte wise, like `strcmp(strtolower())`.
     */
    public function binaryLower(string $expression): string;

    /**
     * Compare byte wise and keep the case.
     */
    public function binary(string $expression): string;

    /**
     * True when the keyword (already folded and stored byte wise) contains the folded needle.
     */
    public function binaryContains(string $expression, string $value, Binder $binder): string;
}

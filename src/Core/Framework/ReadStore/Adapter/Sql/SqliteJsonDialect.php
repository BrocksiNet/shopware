<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Adapter\Sql;

use Doctrine\DBAL\Connection;
use Pdo\Sqlite;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\ReadStore\Evaluation\QueryEvaluator;
use Shopware\Core\Framework\ReadStore\Port\NotSupportedByEngine;

/**
 * SQLite 3.51 with the JSON functions. SQLite has no ICU, so two PHP callbacks are registered on the native connection:
 * `rs_fold()` (the exact fold of the reference evaluator) and the collation `rs_unicode` (the reference's ICU collator at
 * primary strength). The callbacks are bound to the native connection and registered again when it is replaced.
 *
 * @internal
 */
#[Package('framework')]
final class SqliteJsonDialect implements JsonDialect
{
    private ?int $registeredOn = null;

    private ?\Collator $collator = null;

    public function name(): string
    {
        return 'sqlite';
    }

    public function bootstrap(Connection $connection): void
    {
        $native = $connection->getNativeConnection();
        if (!$native instanceof Sqlite) {
            throw NotSupportedByEngine::feature('sqlite', 'a connection that is not pdo_sqlite');
        }

        if ($this->registeredOn === spl_object_id($native)) {
            return;
        }

        $native->createFunction('rs_fold', static fn (mixed $value): ?string => $value === null ? null : QueryEvaluator::fold((string) $value), 1, Sqlite::DETERMINISTIC);
        $native->createCollation('rs_unicode', function (string $left, string $right): int {
            $this->collator ??= new \Collator('root');
            $this->collator->setStrength(\Collator::PRIMARY);
            $result = $this->collator->compare($left, $right);

            return $result === false ? strcmp($left, $right) : $result;
        });
        $this->registeredOn = spl_object_id($native);
    }

    public function setupDatabase(Connection $connection): void
    {
    }

    public function quote(string $identifier): string
    {
        return '"' . $identifier . '"';
    }

    public function createMetaTable(string $table): array
    {
        return ['CREATE TABLE IF NOT EXISTS ' . $this->quote($table) . ' (name TEXT NOT NULL PRIMARY KEY, value TEXT NOT NULL)'];
    }

    public function createDocumentTable(string $table): array
    {
        return [
            'CREATE TABLE ' . $this->quote($table) . ' (id TEXT NOT NULL PRIMARY KEY, active INTEGER NULL, manufacturer_id TEXT NULL, display_group TEXT NULL, doc TEXT NOT NULL CHECK (json_valid(doc)))',
            'CREATE INDEX ' . $this->quote($table . '_active') . ' ON ' . $this->quote($table) . ' (active)',
            'CREATE INDEX ' . $this->quote($table . '_manufacturer') . ' ON ' . $this->quote($table) . ' (manufacturer_id)',
            'CREATE INDEX ' . $this->quote($table . '_display_group') . ' ON ' . $this->quote($table) . ' (display_group)',
        ];
    }

    public function createKeywordTable(string $table): array
    {
        return [
            'CREATE TABLE ' . $this->quote($table) . ' (product_id TEXT NOT NULL, language_id TEXT NOT NULL, keyword_fold TEXT NOT NULL, ranking REAL NOT NULL)',
            'CREATE INDEX ' . $this->quote($table . '_product') . ' ON ' . $this->quote($table) . ' (product_id)',
            'CREATE INDEX ' . $this->quote($table . '_lookup') . ' ON ' . $this->quote($table) . ' (language_id, keyword_fold)',
        ];
    }

    public function upsertDocument(string $table): string
    {
        return 'INSERT INTO ' . $this->quote($table) . ' (id, active, manufacturer_id, display_group, doc)'
            . ' VALUES (:id, :active, :manufacturer_id, :display_group, :doc)'
            . ' ON CONFLICT (id) DO UPDATE SET active = excluded.active, manufacturer_id = excluded.manufacturer_id, display_group = excluded.display_group, doc = excluded.doc';
    }

    public function upsertMeta(string $table): string
    {
        return 'INSERT INTO ' . $this->quote($table) . ' (name, value) VALUES (:name, :value) ON CONFLICT (name) DO UPDATE SET value = excluded.value';
    }

    public function position(string $alias): string
    {
        // the rowid of a replaced row stays, like the position of a replaced document in the Array store
        return $alias . '.rowid';
    }

    public function limit(?int $limit, int $offset): string
    {
        return ' LIMIT ' . ($limit ?? -1) . ($offset > 0 ? ' OFFSET ' . $offset : '');
    }

    public function orderTerm(string $expression, string $direction): string
    {
        return $expression . ' ' . $direction . ($direction === 'ASC' ? ' NULLS FIRST' : ' NULLS LAST');
    }

    public function kind(string $doc, array $path, Binder $binder): string
    {
        return 'CASE ' . $this->type($doc, $path, $binder) . '
            WHEN \'text\' THEN \'S\' WHEN \'integer\' THEN \'N\' WHEN \'real\' THEN \'N\' WHEN \'true\' THEN \'B\' WHEN \'false\' THEN \'B\'
            WHEN \'object\' THEN \'O\' WHEN \'array\' THEN \'O\'
        END';
    }

    public function text(string $doc, array $path, Binder $binder): string
    {
        return 'CASE WHEN ' . $this->type($doc, $path, $binder) . ' = \'text\' THEN ' . $this->extract($doc, $path, $binder) . ' END';
    }

    public function number(string $doc, array $path, Binder $binder): string
    {
        return 'CASE WHEN ' . $this->type($doc, $path, $binder) . ' IN (\'integer\', \'real\') THEN CAST(' . $this->extract($doc, $path, $binder) . ' AS REAL) END';
    }

    public function bool(string $doc, array $path, Binder $binder): string
    {
        return 'CASE ' . $this->type($doc, $path, $binder) . ' WHEN \'true\' THEN 1 WHEN \'false\' THEN 0 END';
    }

    public function isArray(string $doc, array $path, Binder $binder): string
    {
        return $this->type($doc, $path, $binder) . ' = \'array\'';
    }

    public function arrayNotEmpty(string $doc, array $path, Binder $binder): string
    {
        return 'CASE WHEN ' . $this->type($doc, $path, $binder) . ' = \'array\' THEN json_array_length(' . $doc . ', ' . $binder->bind($this->jsonPath($path)) . ') > 0 ELSE 0 END';
    }

    public function arrayContains(string $doc, array $path, string $value, Binder $binder): string
    {
        return 'CASE WHEN ' . $this->type($doc, $path, $binder) . ' = \'array\' THEN EXISTS (SELECT 1 FROM json_each(' . $doc . ', ' . $binder->bind($this->jsonPath($path)) . ') AS je WHERE je.type = \'text\' AND je.value = ' . $binder->bind($value) . ') END';
    }

    public function indexedArrayContains(string $doc, array $path, string $value, Binder $binder): ?string
    {
        return null;
    }

    public function unnest(string $doc, array $path, Binder $binder): array
    {
        return [
            'from' => ', json_each(' . $doc . ', ' . $binder->bind($this->jsonPath($path)) . ') AS jt',
            'value' => 'jt.value',
        ];
    }

    public function toFloat(string $expression): string
    {
        return 'CAST(' . $expression . ' AS REAL)';
    }

    public function ciEquals(string $expression, string $value, Binder $binder): string
    {
        return 'rs_fold(' . $expression . ') = ' . $binder->bind(QueryEvaluator::fold($value));
    }

    public function ciLike(string $expression, string $value, string $mode, Binder $binder): string
    {
        $needle = QueryEvaluator::fold($value);
        if ($mode === 'contains') {
            return 'instr(rs_fold(' . $expression . '), ' . $binder->bind($needle) . ') > 0';
        }

        return 'substr(rs_fold(' . $expression . '), 1, ' . mb_strlen($needle) . ') = ' . $binder->bind($needle);
    }

    public function ciCollate(string $expression): string
    {
        return $expression . ' COLLATE rs_unicode';
    }

    public function ciKey(string $expression): string
    {
        return 'rs_fold(' . $expression . ')';
    }

    public function binaryLower(string $expression): string
    {
        return 'lower(' . $expression . ')';
    }

    public function binary(string $expression): string
    {
        return $expression;
    }

    public function binaryContains(string $expression, string $value, Binder $binder): string
    {
        return 'instr(' . $expression . ', ' . $binder->bind($value) . ') > 0';
    }

    /**
     * @param list<string> $path
     */
    private function jsonPath(array $path): string
    {
        $json = '$';
        foreach ($path as $key) {
            $json .= '."' . addcslashes($key, '"\\') . '"';
        }

        return $json;
    }

    /**
     * @param list<string> $path
     */
    private function type(string $doc, array $path, Binder $binder): string
    {
        return 'json_type(' . $doc . ', ' . $binder->bind($this->jsonPath($path)) . ')';
    }

    /**
     * @param list<string> $path
     */
    private function extract(string $doc, array $path, Binder $binder): string
    {
        return 'json_extract(' . $doc . ', ' . $binder->bind($this->jsonPath($path)) . ')';
    }
}

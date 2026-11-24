<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Adapter\Sql;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Log\Package;

/**
 * PostgreSQL 18, `jsonb`. The case and accent insensitive comparison is a non deterministic ICU collation
 * (`und-u-ks-level1`), which PostgreSQL 18 supports for `=`, `LIKE`, `ORDER BY`, `DISTINCT` and `min()`.
 *
 * The jsonb operator `?` is never used, it collides with PDO placeholders. Containment (`@>`) takes its place.
 *
 * @internal
 */
#[Package('framework')]
final class PostgreSqlJsonDialect implements JsonDialect
{
    private const COLLATION = 'rs_unicode_ci';

    public function name(): string
    {
        return 'pgsql';
    }

    public function bootstrap(Connection $connection): void
    {
    }

    public function setupDatabase(Connection $connection): void
    {
        $connection->executeStatement('CREATE COLLATION IF NOT EXISTS ' . self::COLLATION . ' (provider = icu, locale = \'und-u-ks-level1\', deterministic = false)');
    }

    public function quote(string $identifier): string
    {
        return '"' . $identifier . '"';
    }

    public function createMetaTable(string $table): array
    {
        return ['CREATE TABLE IF NOT EXISTS ' . $this->quote($table) . ' (name TEXT COLLATE "C" NOT NULL PRIMARY KEY, value TEXT COLLATE "C" NOT NULL)'];
    }

    public function createDocumentTable(string $table): array
    {
        return [
            'CREATE TABLE ' . $this->quote($table) . ' (
                seq BIGSERIAL NOT NULL,
                id TEXT COLLATE "C" NOT NULL PRIMARY KEY,
                active SMALLINT NULL,
                manufacturer_id TEXT COLLATE "C" NULL,
                display_group TEXT COLLATE "C" NULL,
                doc JSONB NOT NULL
            )',
            'CREATE INDEX ' . $this->quote($table . '_active') . ' ON ' . $this->quote($table) . ' (active)',
            'CREATE INDEX ' . $this->quote($table . '_manufacturer') . ' ON ' . $this->quote($table) . ' (manufacturer_id)',
            'CREATE INDEX ' . $this->quote($table . '_display_group') . ' ON ' . $this->quote($table) . ' (display_group)',
            'CREATE INDEX ' . $this->quote($table . '_cat') . ' ON ' . $this->quote($table) . ' USING GIN ((doc->\'categoryTree\'))',
            'CREATE INDEX ' . $this->quote($table . '_prop') . ' ON ' . $this->quote($table) . ' USING GIN ((doc->\'propertyIds\'))',
            'CREATE INDEX ' . $this->quote($table . '_opt') . ' ON ' . $this->quote($table) . ' USING GIN ((doc->\'optionIds\'))',
        ];
    }

    public function createKeywordTable(string $table): array
    {
        return [
            'CREATE TABLE ' . $this->quote($table) . ' (
                product_id TEXT COLLATE "C" NOT NULL,
                language_id TEXT COLLATE "C" NOT NULL,
                keyword_fold TEXT COLLATE "C" NOT NULL,
                ranking DOUBLE PRECISION NOT NULL
            )',
            'CREATE INDEX ' . $this->quote($table . '_product') . ' ON ' . $this->quote($table) . ' (product_id)',
            'CREATE INDEX ' . $this->quote($table . '_lookup') . ' ON ' . $this->quote($table) . ' (language_id, keyword_fold)',
        ];
    }

    public function upsertDocument(string $table): string
    {
        return 'INSERT INTO ' . $this->quote($table) . ' (id, active, manufacturer_id, display_group, doc)'
            . ' VALUES (:id, :active, :manufacturer_id, :display_group, CAST(:doc AS JSONB))'
            . ' ON CONFLICT (id) DO UPDATE SET active = EXCLUDED.active, manufacturer_id = EXCLUDED.manufacturer_id, display_group = EXCLUDED.display_group, doc = EXCLUDED.doc';
    }

    public function upsertMeta(string $table): string
    {
        return 'INSERT INTO ' . $this->quote($table) . ' (name, value) VALUES (:name, :value) ON CONFLICT (name) DO UPDATE SET value = EXCLUDED.value';
    }

    public function position(string $alias): string
    {
        return $alias . '.seq';
    }

    public function limit(?int $limit, int $offset): string
    {
        return ' LIMIT ' . ($limit ?? 'ALL') . ($offset > 0 ? ' OFFSET ' . $offset : '');
    }

    public function orderTerm(string $expression, string $direction): string
    {
        return $expression . ' ' . $direction . ($direction === 'ASC' ? ' NULLS FIRST' : ' NULLS LAST');
    }

    public function kind(string $doc, array $path, Binder $binder): string
    {
        return 'CASE jsonb_typeof(' . $this->extract($doc, $path, $binder) . ')
            WHEN \'string\' THEN \'S\' WHEN \'number\' THEN \'N\' WHEN \'boolean\' THEN \'B\' WHEN \'object\' THEN \'O\' WHEN \'array\' THEN \'O\'
        END';
    }

    public function text(string $doc, array $path, Binder $binder): string
    {
        return 'CASE WHEN jsonb_typeof(' . $this->extract($doc, $path, $binder) . ') = \'string\' THEN ' . $this->extractText($doc, $path, $binder) . ' END';
    }

    public function number(string $doc, array $path, Binder $binder): string
    {
        return 'CASE WHEN jsonb_typeof(' . $this->extract($doc, $path, $binder) . ') = \'number\' THEN CAST(' . $this->extractText($doc, $path, $binder) . ' AS DOUBLE PRECISION) END';
    }

    public function bool(string $doc, array $path, Binder $binder): string
    {
        return 'CASE WHEN jsonb_typeof(' . $this->extract($doc, $path, $binder) . ') = \'boolean\' THEN CASE ' . $this->extractText($doc, $path, $binder) . ' WHEN \'true\' THEN 1 ELSE 0 END END';
    }

    public function isArray(string $doc, array $path, Binder $binder): string
    {
        return 'jsonb_typeof(' . $this->extract($doc, $path, $binder) . ') = \'array\'';
    }

    public function arrayNotEmpty(string $doc, array $path, Binder $binder): string
    {
        return 'CASE WHEN jsonb_typeof(' . $this->extract($doc, $path, $binder) . ') = \'array\' THEN jsonb_array_length(' . $this->extract($doc, $path, $binder) . ') > 0 ELSE FALSE END';
    }

    public function arrayContains(string $doc, array $path, string $value, Binder $binder): string
    {
        return 'CASE WHEN jsonb_typeof(' . $this->extract($doc, $path, $binder) . ') = \'array\' THEN ' . $this->extract($doc, $path, $binder) . ' @> to_jsonb(CAST(' . $binder->bind($value) . ' AS TEXT)) END';
    }

    public function indexedArrayContains(string $doc, array $path, string $value, Binder $binder): ?string
    {
        if (\count($path) !== 1 || !\in_array($path[0], ['categoryTree', 'propertyIds', 'optionIds'], true)) {
            return null;
        }

        return '((' . $doc . '->\'' . $path[0] . '\') @> jsonb_build_array(CAST(' . $binder->bind($value) . ' AS TEXT)))';
    }

    public function unnest(string $doc, array $path, Binder $binder): array
    {
        $list = $this->extract($doc, $path, $binder);

        return [
            'from' => ', LATERAL jsonb_array_elements_text(CASE WHEN jsonb_typeof(' . $list . ') = \'array\' THEN ' . $list . ' ELSE \'[]\'::jsonb END) AS jt(e)',
            'value' => 'jt.e',
        ];
    }

    public function toFloat(string $expression): string
    {
        return 'CAST(' . $expression . ' AS DOUBLE PRECISION)';
    }

    public function ciEquals(string $expression, string $value, Binder $binder): string
    {
        return '(' . $this->ciCollate($expression) . ') = ' . $binder->bind($value);
    }

    public function ciLike(string $expression, string $value, string $mode, Binder $binder): string
    {
        $pattern = addcslashes($value, '\\%_');

        return '(' . $this->ciCollate($expression) . ') LIKE ' . $binder->bind($mode === 'contains' ? '%' . $pattern . '%' : $pattern . '%');
    }

    public function ciCollate(string $expression): string
    {
        return $expression . ' COLLATE ' . self::COLLATION;
    }

    public function ciKey(string $expression): string
    {
        return $expression . ' COLLATE ' . self::COLLATION;
    }

    public function binaryLower(string $expression): string
    {
        return '(LOWER(' . $expression . ') COLLATE "C")';
    }

    public function binary(string $expression): string
    {
        return '(' . $expression . ' COLLATE "C")';
    }

    public function binaryContains(string $expression, string $value, Binder $binder): string
    {
        return 'POSITION(CAST(' . $binder->bind($value) . ' AS TEXT) IN ' . $expression . ') > 0';
    }

    /**
     * @param list<string> $path
     */
    private function keys(array $path, Binder $binder): string
    {
        return 'ARRAY[' . implode(', ', array_map(fn (string $key): string => 'CAST(' . $binder->bind($key) . ' AS TEXT)', $path)) . ']';
    }

    /**
     * @param list<string> $path
     */
    private function extract(string $doc, array $path, Binder $binder): string
    {
        return '(' . $doc . ' #> ' . $this->keys($path, $binder) . ')';
    }

    /**
     * @param list<string> $path
     */
    private function extractText(string $doc, array $path, Binder $binder): string
    {
        return '(' . $doc . ' #>> ' . $this->keys($path, $binder) . ')';
    }
}

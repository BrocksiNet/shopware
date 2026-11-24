<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Adapter\Sql;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\ReadStore\Port\NotSupportedByEngine;

/**
 * MySQL 8.4. MariaDB shares almost everything, see {@see MariaDbJsonDialect}.
 *
 * `JSON_VALUE` is not used on purpose: on MySQL it returns `varchar(512)` and silently yields NULL for longer strings
 * (verified with a 1000 character string), so a description would vanish. `JSON_UNQUOTE(JSON_EXTRACT())` behind a
 * `JSON_TYPE` check returns the full value on both engines.
 *
 * @internal
 */
#[Package('framework')]
class MySqlJsonDialect implements JsonDialect
{
    public function name(): string
    {
        return 'mysql';
    }

    public function bootstrap(Connection $connection): void
    {
    }

    public function setupDatabase(Connection $connection): void
    {
    }

    public function quote(string $identifier): string
    {
        return '`' . $identifier . '`';
    }

    public function createMetaTable(string $table): array
    {
        return ['CREATE TABLE IF NOT EXISTS ' . $this->quote($table) . ' (
            name VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
            value VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'];
    }

    public function createDocumentTable(string $table): array
    {
        return ['CREATE TABLE ' . $this->quote($table) . ' (
            seq BIGINT NOT NULL AUTO_INCREMENT,
            id VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
            active TINYINT(1) NULL,
            manufacturer_id VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
            display_group VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
            doc ' . $this->documentType() . ' NOT NULL' . $this->documentConstraint() . ',
            PRIMARY KEY (id),
            UNIQUE KEY ' . $this->quote($table . '_seq') . ' (seq),
            KEY ' . $this->quote($table . '_active') . ' (active),
            KEY ' . $this->quote($table . '_manufacturer') . ' (manufacturer_id),
            KEY ' . $this->quote($table . '_display_group') . ' (display_group)' . $this->multiValuedIndexes($table) . '
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'];
    }

    public function createKeywordTable(string $table): array
    {
        return ['CREATE TABLE ' . $this->quote($table) . ' (
            product_id VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
            language_id VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
            keyword_fold VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
            ranking DOUBLE NOT NULL,
            KEY ' . $this->quote($table . '_product') . ' (product_id),
            KEY ' . $this->quote($table . '_lookup') . ' (language_id, keyword_fold)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'];
    }

    public function upsertDocument(string $table): string
    {
        return 'INSERT INTO ' . $this->quote($table) . ' (id, active, manufacturer_id, display_group, doc) VALUES (:id, :active, :manufacturer_id, :display_group, :doc)'
            . ' ON DUPLICATE KEY UPDATE active = VALUES(active), manufacturer_id = VALUES(manufacturer_id), display_group = VALUES(display_group), doc = VALUES(doc)';
    }

    public function upsertMeta(string $table): string
    {
        return 'INSERT INTO ' . $this->quote($table) . ' (name, value) VALUES (:name, :value) ON DUPLICATE KEY UPDATE value = VALUES(value)';
    }

    public function position(string $alias): string
    {
        return $alias . '.seq';
    }

    public function limit(?int $limit, int $offset): string
    {
        return ' LIMIT ' . ($limit ?? '18446744073709551615') . ($offset > 0 ? ' OFFSET ' . $offset : '');
    }

    public function orderTerm(string $expression, string $direction): string
    {
        // NULL is the smallest value: first when ascending, last when descending, which is what the reference does
        return $expression . ' ' . $direction;
    }

    public function kind(string $doc, array $path, Binder $binder): string
    {
        return 'CASE JSON_TYPE(' . $this->extract($doc, $path, $binder) . ')
            WHEN \'STRING\' THEN \'S\'
            WHEN \'INTEGER\' THEN \'N\' WHEN \'UNSIGNED INTEGER\' THEN \'N\' WHEN \'DOUBLE\' THEN \'N\' WHEN \'DECIMAL\' THEN \'N\'
            WHEN \'BOOLEAN\' THEN \'B\'
            WHEN \'OBJECT\' THEN \'O\' WHEN \'ARRAY\' THEN \'O\'
        END';
    }

    public function text(string $doc, array $path, Binder $binder): string
    {
        return 'CASE WHEN JSON_TYPE(' . $this->extract($doc, $path, $binder) . ') = \'STRING\' THEN JSON_UNQUOTE(' . $this->extract($doc, $path, $binder) . ') END';
    }

    public function number(string $doc, array $path, Binder $binder): string
    {
        return 'CASE WHEN JSON_TYPE(' . $this->extract($doc, $path, $binder) . ') IN (\'INTEGER\', \'UNSIGNED INTEGER\', \'DOUBLE\', \'DECIMAL\') THEN CAST(' . $this->extract($doc, $path, $binder) . ' AS DOUBLE) END';
    }

    public function bool(string $doc, array $path, Binder $binder): string
    {
        return 'CASE WHEN JSON_TYPE(' . $this->extract($doc, $path, $binder) . ') = \'BOOLEAN\' THEN CASE JSON_UNQUOTE(' . $this->extract($doc, $path, $binder) . ') WHEN \'true\' THEN 1 ELSE 0 END END';
    }

    public function isArray(string $doc, array $path, Binder $binder): string
    {
        return 'JSON_TYPE(' . $this->extract($doc, $path, $binder) . ') = \'ARRAY\'';
    }

    public function arrayNotEmpty(string $doc, array $path, Binder $binder): string
    {
        return 'CASE WHEN JSON_TYPE(' . $this->extract($doc, $path, $binder) . ') = \'ARRAY\' THEN JSON_LENGTH(' . $this->extract($doc, $path, $binder) . ') > 0 ELSE FALSE END';
    }

    public function arrayContains(string $doc, array $path, string $value, Binder $binder): string
    {
        // JSON_CONTAINS works on both engines, MEMBER OF is a syntax error on MariaDB
        return 'CASE WHEN JSON_TYPE(' . $this->extract($doc, $path, $binder) . ') = \'ARRAY\' THEN JSON_CONTAINS(' . $this->extract($doc, $path, $binder) . ', JSON_QUOTE(' . $binder->bind($value) . ')) END';
    }

    public function indexedArrayContains(string $doc, array $path, string $value, Binder $binder): ?string
    {
        if (\count($path) !== 1 || !\in_array($path[0], ['categoryTree', 'propertyIds', 'optionIds'], true)) {
            return null;
        }

        return '(' . $binder->bind($value) . ' MEMBER OF (' . $doc . '->\'$.' . $path[0] . '\'))';
    }

    public function unnest(string $doc, array $path, Binder $binder): array
    {
        if (\count($path) !== 1 || !preg_match('/^\w+\z/', $path[0])) {
            throw NotSupportedByEngine::field(implode('.', $path), 'only plain top level lists can be unnested');
        }

        return [
            'from' => ', JSON_TABLE(' . $doc . ', \'$."' . $path[0] . '"[*]\' COLUMNS (e VARCHAR(255) PATH \'$\')) AS jt',
            'value' => 'jt.e',
        ];
    }

    public function toFloat(string $expression): string
    {
        return 'CAST(' . $expression . ' AS DOUBLE)';
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
        return $expression . ' COLLATE utf8mb4_unicode_ci';
    }

    public function ciKey(string $expression): string
    {
        return $expression . ' COLLATE utf8mb4_unicode_ci';
    }

    public function binaryLower(string $expression): string
    {
        return '(LOWER(' . $expression . ') COLLATE utf8mb4_bin)';
    }

    public function binary(string $expression): string
    {
        return '(' . $expression . ' COLLATE utf8mb4_bin)';
    }

    public function binaryContains(string $expression, string $value, Binder $binder): string
    {
        return 'LOCATE(' . $binder->bind($value) . ', ' . $this->binary($expression) . ') > 0';
    }

    protected function documentType(): string
    {
        return 'JSON';
    }

    protected function documentConstraint(): string
    {
        return '';
    }

    protected function multiValuedIndexes(string $table): string
    {
        return ',
            KEY ' . $this->quote($table . '_cat') . ' ((CAST(doc->\'$.categoryTree\' AS CHAR(64) ARRAY))),
            KEY ' . $this->quote($table . '_prop') . ' ((CAST(doc->\'$.propertyIds\' AS CHAR(64) ARRAY))),
            KEY ' . $this->quote($table . '_opt') . ' ((CAST(doc->\'$.optionIds\' AS CHAR(64) ARRAY)))';
    }

    /**
     * @param list<string> $path
     */
    private function extract(string $doc, array $path, Binder $binder): string
    {
        $json = '$';
        foreach ($path as $key) {
            $json .= '."' . addcslashes($key, '"\\') . '"';
        }

        return 'JSON_EXTRACT(' . $doc . ', ' . $binder->bind($json) . ')';
    }
}

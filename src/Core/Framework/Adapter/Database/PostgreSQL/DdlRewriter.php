<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Adapter\Database\PostgreSQL;

use Shopware\Core\Framework\Adapter\AdapterException;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Util\Hasher;

/**
 * Rewrites MySQL DDL (migrations, schema.sql) into PostgreSQL DDL.
 * Expects statements that already went through the MySQLToPostgreSQLTranslator (identifiers in double quotes, string literals in single quotes).
 * The type mapping is the one of docs/inventory/schema/mysql-to-pg-ddl.py:
 *
 * - BINARY(16) => uuid, TINYINT(1) => boolean, JSON / LONGTEXT with CHECK (JSON_VALID) => jsonb, BLOB / VARBINARY => bytea
 * - DATETIME(n) => timestamp(n), INT UNSIGNED => bigint, strings with utf8mb4_unicode_ci => COLLATE "ci"
 * - KEY / UNIQUE KEY => CREATE [UNIQUE] INDEX "table.name" (index names are schema wide on PostgreSQL)
 * - a foreign key gets the index MySQL creates implicitly, named like the constraint
 * - AUTO_INCREMENT => identity, table options, COMMENT, ON UPDATE CURRENT_TIMESTAMP, AFTER / FIRST are dropped
 * - MODIFY / CHANGE => one ALTER COLUMN per aspect (type, null, default), CHANGE adds RENAME COLUMN
 * - RENAME TABLE, DROP INDEX x ON t, DROP FOREIGN KEY, DROP PRIMARY KEY
 *
 * MySQL session switches (`SET default_storage_engine`, `SET foreign_key_checks`, ...) become `SELECT 1`.
 * Statements that are not DDL are returned unchanged.
 *
 * @internal
 */
#[Package('framework')]
class DdlRewriter
{
    /**
     * Marks the indexes created for a foreign key, MySQL drops only those when another index covers the columns
     */
    private const IMPLICIT_FK_INDEX = 'implicit foreign key index';

    private const IDENT = '(?:"(?:[^"]|"")+"|[A-Za-z_][A-Za-z0-9_$]*)';

    /**
     * Columns that hold PHP serialize() output with NUL bytes, `text` can not store them (docs/phase3 serialized columns audit)
     */
    private const SERIALIZED_TEXT_COLUMNS = ['product.cheapest_price'];

    /**
     * @var \Closure(string, string): ?string|null
     */
    private ?\Closure $columnTypeLookup;

    /**
     * @param \Closure(string, string): ?string|null $columnTypeLookup returns the PostgreSQL type of an existing column (table, column), used by MODIFY so a jsonb column stays jsonb
     */
    public function __construct(?\Closure $columnTypeLookup = null)
    {
        $this->columnTypeLookup = $columnTypeLookup;
    }

    public static function isDdl(string $sql): bool
    {
        return preg_match('/^(?:\s|--[^\n]*\n|\/\*.*?\*\/)*(?:CREATE\s+(?:UNIQUE\s+|TEMPORARY\s+)?(?:TABLE|INDEX)|ALTER\s+TABLE|DROP\s+INDEX|DROP\s+TRIGGER|RENAME\s+TABLE|SET\s+(?:SESSION\s+|GLOBAL\s+)?(?:default_storage_engine|foreign_key_checks|unique_checks|sql_mode|sql_notes|group_concat_max_len|innodb_\w+))\b/is', $sql) === 1
            || (str_contains($sql, ';') && preg_match('/;\s*(?:CREATE\s+(?:UNIQUE\s+|TEMPORARY\s+)?(?:TABLE|INDEX)|ALTER\s+TABLE|DROP\s+INDEX|RENAME\s+TABLE)\b/i', $sql) === 1);
    }

    public function rewrite(string $sql): string
    {
        if (!self::isDdl($sql)) {
            return $sql;
        }

        $out = [];
        foreach (self::splitTopLevel($sql, ';') as $statement) {
            if (trim($statement) === '') {
                continue;
            }
            array_push($out, ...$this->rewriteStatement($statement));
        }

        return implode(";\n", $out);
    }

    public static function indexName(string $table, string $name): string
    {
        $full = $table . '.' . $name;
        if (\strlen($full) <= 63) {
            return $full;
        }

        return substr($full, 0, 54) . '_' . substr(Hasher::hash($full, 'md5'), 0, 8);
    }

    /**
     * @return list<string>
     */
    public static function splitTopLevel(string $s, string $separator = ','): array
    {
        $parts = [];
        $depth = 0;
        $start = 0;
        $length = \strlen($s);
        for ($i = 0; $i < $length; ++$i) {
            $char = $s[$i];
            $skipped = self::skipLiteralOrComment($s, $i);
            if ($skipped !== null) {
                $i = $skipped;

                continue;
            }
            if ($char === '(') {
                ++$depth;
            } elseif ($char === ')') {
                --$depth;
            } elseif ($char === $separator && $depth === 0) {
                $parts[] = substr($s, $start, $i - $start);
                $start = $i + 1;
            }
        }
        $parts[] = substr($s, $start);

        return $parts;
    }

    /**
     * @return list<string>
     */
    private function rewriteStatement(string $statement): array
    {
        $statement = trim(preg_replace('/^(?:\s|--[^\n]*\n|\/\*.*?\*\/)+/s', '', $statement) ?? $statement);

        // `SET foreign_key_checks = 0|1` switches the foreign key triggers of the session, a role without the privilege keeps the checks
        if (preg_match('/^SET\s+(?:SESSION\s+)?foreign_key_checks\s*=\s*(0|1|ON|OFF|TRUE|FALSE)\s*;?$/i', $statement, $m) === 1) {
            $role = \in_array(strtolower($m[1]), ['0', 'off', 'false'], true) ? 'replica' : 'origin';

            return ['DO $$ BEGIN PERFORM set_config(\'session_replication_role\', \'' . $role . '\', false); EXCEPTION WHEN insufficient_privilege THEN NULL; END $$'];
        }

        // MySQL session switches without a PostgreSQL counterpart
        if (preg_match('/^SET\s+(?:SESSION\s+|GLOBAL\s+)?(?:default_storage_engine|foreign_key_checks|unique_checks|sql_mode|sql_notes|group_concat_max_len|innodb_\w+)\b/i', $statement) === 1) {
            return ['SELECT 1'];
        }

        // triggers only exist for the blue green deployment of MySQL, there is nothing to drop
        if (preg_match('/^DROP\s+TRIGGER\b/i', $statement) === 1) {
            return ['SELECT 1'];
        }

        if (preg_match('/^CREATE\s+(?:TEMPORARY\s+)?TABLE\b/i', $statement) === 1) {
            return $this->createTable($statement) ?? [$statement];
        }
        if (preg_match('/^ALTER\s+TABLE\s+(' . self::IDENT . ')\s+(.*)$/isD', $statement, $m) === 1) {
            return $this->alterTable(self::ident($m[1]), $m[2]);
        }
        if (preg_match('/^CREATE\s+(UNIQUE\s+)?INDEX\s+(?:IF\s+NOT\s+EXISTS\s+)?(' . self::IDENT . ')\s+(?:USING\s+\w+\s+)?ON\s+(' . self::IDENT . ')\s*\((.*)\)\s*(?:USING\s+\w+)?$/isD', $statement, $m) === 1) {
            return [$this->createIndex(self::ident($m[3]), self::ident($m[2]), $m[4], trim($m[1]) !== '')];
        }
        if (preg_match('/^DROP\s+INDEX\s+(' . self::IDENT . ')\s+ON\s+(' . self::IDENT . ')\b.*$/isD', $statement, $m) === 1) {
            return ['DROP INDEX IF EXISTS ' . self::quote(self::indexName(self::ident($m[2]), self::ident($m[1])))];
        }
        if (preg_match('/^RENAME\s+TABLE\s+(.*)$/isD', $statement, $m) === 1) {
            $statements = [];
            foreach (self::splitTopLevel($m[1]) as $pair) {
                if (preg_match('/^\s*(' . self::IDENT . ')\s+TO\s+(' . self::IDENT . ')\s*$/isD', $pair, $p) === 1) {
                    $statements[] = 'ALTER TABLE ' . self::quote(self::ident($p[1])) . ' RENAME TO ' . self::quote(self::ident($p[2]));
                }
            }

            return $statements !== [] ? $statements : [$statement];
        }

        return [$statement];
    }

    /**
     * @return list<string>|null
     */
    private function createTable(string $statement): ?array
    {
        if (preg_match('/^CREATE\s+(TEMPORARY\s+)?TABLE\s+(IF\s+NOT\s+EXISTS\s*)?(' . self::IDENT . ')\s*\(/is', $statement, $m, \PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $open = $m[0][1] + \strlen($m[0][0]) - 1;
        $close = self::matchParen($statement, $open);
        if ($close === null) {
            return null;
        }

        $table = self::ident($m[3][0]);
        $items = self::splitTopLevel(substr($statement, $open + 1, $close - $open - 1));
        $options = substr($statement, $close + 1);
        if (preg_match('/\bCOMMENT\s*=?\s*(\'(?:[^\'\\\\]|\\\\.|\'\')*\')/is', $options, $tc) === 1) {
            $tableComment = $tc[1];
        }
        $tableCollation = preg_match('/\bCOLLATE\s*=?\s*(\w+)/i', $options, $c) === 1 ? strtolower($c[1]) : 'utf8mb4_unicode_ci';

        $jsonColumns = [];
        foreach ($items as $item) {
            if (preg_match('/CHECK\s*\(\s*JSON_VALID\s*\(\s*(' . self::IDENT . ')\s*\)\s*\)/i', $item, $j) === 1) {
                $jsonColumns[self::ident($j[1])] = true;
            }
        }

        $lines = [];
        $indexes = [];
        $indexedPrefixes = [];
        $comments = [];
        $foreignKeys = [];
        $unnamedForeignKeys = 0;
        foreach ($items as $item) {
            $item = trim($item);

            if (preg_match('/^(?:CONSTRAINT\s+' . self::IDENT . '\s+)?CHECK\s*\(\s*JSON_VALID\b/i', $item) === 1) {
                continue;
            }
            if (preg_match('/^(?:CONSTRAINT\s+(?:' . self::IDENT . ')\s+)?CHECK\b/i', $item) === 1) {
                $lines[] = $item;

                continue;
            }
            if (preg_match('/^PRIMARY\s+KEY\s*(?:USING\s+\w+\s*)?(?:(?:`[^`]+`|' . self::IDENT . ')\s*)?\(([^)]*)\)/i', $item, $k) === 1) {
                $lines[] = 'PRIMARY KEY (' . self::columnList($k[1], false) . ')';
                $indexedPrefixes[] = self::columnNames($k[1]);

                continue;
            }
            if (preg_match('/^(?:CONSTRAINT\s+(?:(' . self::IDENT . ')\s+)?)?UNIQUE(?:\s+(?:KEY|INDEX))?\s*(' . self::IDENT . ')?\s*(?:USING\s+\w+\s*)?\((.*)\)\s*(?:USING\s+\w+)?$/isD', $item, $k) === 1) {
                $names = self::columnNames($k[3]);
                $name = $k[2] !== '' ? self::ident($k[2]) : ($k[1] !== '' ? self::ident($k[1]) : $names[0]);
                $indexes[] = $this->createIndex($table, $name, $k[3], true);
                $indexedPrefixes[] = $names;

                continue;
            }
            if (preg_match('/^(?:FULLTEXT|SPATIAL)\b/i', $item) === 1) {
                continue;
            }
            if (preg_match('/^(?:KEY|INDEX)\s*(' . self::IDENT . ')?\s*(?:USING\s+\w+\s*)?\((.*)\)\s*(?:USING\s+\w+)?$/isD', $item, $k) === 1) {
                $indexes[] = $this->createIndex($table, $k[1] !== '' ? self::ident($k[1]) : self::columnNames($k[2])[0], $k[2], false);
                $indexedPrefixes[] = self::columnNames($k[2]);

                continue;
            }
            if (preg_match('/^(?:CONSTRAINT\s+(' . self::IDENT . ')\s+)?FOREIGN\s+KEY\s*(?:' . self::IDENT . '\s*)?\(([^)]*)\)\s*(REFERENCES\s+.*)$/isD', $item, $k) === 1) {
                // MySQL names an unnamed foreign key <table>_ibfk_<n>, counted per table
                $name = $k[1] !== '' ? self::ident($k[1]) : $table . '_ibfk_' . ++$unnamedForeignKeys;
                $lines[] = 'CONSTRAINT ' . self::quote($name) . ' FOREIGN KEY (' . self::columnList($k[2], false) . ') ' . self::foreignKeyTarget($k[3]);
                $foreignKeys[] = [$name, self::columnNames($k[2])];

                continue;
            }

            $column = $this->parseColumn($item, $table, $jsonColumns, $tableCollation);
            $lines[] = $this->renderColumn($column);
            if (isset($column['comment'])) {
                $comments[] = 'COMMENT ON COLUMN ' . self::quote($table) . '.' . self::quote($column['name']) . ' IS ' . $column['comment'];
            }
            if ($column['primary']) {
                $indexedPrefixes[] = [$column['name']];
            }
            if ($column['unique']) {
                $indexes[] = $this->createIndex($table, $column['name'], self::quote($column['name']), true);
                $indexedPrefixes[] = [$column['name']];
            }
        }

        // MySQL creates an index for a foreign key when no index starts with its columns
        foreach ($foreignKeys as [$name, $columns]) {
            foreach ($indexedPrefixes as $prefix) {
                if (\array_slice($prefix, 0, \count($columns)) === $columns) {
                    continue 2;
                }
            }
            $indexes[] = 'CREATE INDEX ' . self::quote(self::indexName($table, $name)) . ' ON ' . self::quote($table) . ' (' . implode(', ', array_map(self::quote(...), $columns)) . ')';
            $indexes[] = 'COMMENT ON INDEX ' . self::quote(self::indexName($table, $name)) . ' IS ' . self::literal(self::IMPLICIT_FK_INDEX);
            $indexedPrefixes[] = $columns;
        }

        return [
            'CREATE ' . trim(trim($m[1][0]) . ' TABLE ' . trim($m[2][0])) . ' ' . self::quote($table) . " (\n    " . implode(",\n    ", $lines) . "\n)",
            ...$indexes,
            ...$comments,
            ...(isset($tableComment) ? ['COMMENT ON TABLE ' . self::quote($table) . ' IS ' . $tableComment] : []),
        ];
    }

    /**
     * @return list<string>
     */
    private function alterTable(string $table, string $actions): array
    {
        $statements = [];
        $alter = static fn (string $action): string => 'ALTER TABLE ' . self::quote($table) . ' ' . $action;

        foreach (self::splitTopLevel(rtrim(trim($actions), ';')) as $action) {
            $action = trim($action);
            if ($action === '') {
                continue;
            }

            // table options
            if (preg_match('/^(?:ENGINE|AUTO_INCREMENT|ROW_FORMAT|COMMENT|ALGORITHM|LOCK|(?:DEFAULT\s+)?(?:CHARSET|CHARACTER\s+SET|COLLATE)|CONVERT\s+TO|STATS_\w+|KEY_BLOCK_SIZE)\b/i', $action) === 1) {
                continue;
            }

            if (preg_match('/^ADD\s+(?:CONSTRAINT\s+' . self::IDENT . '\s+)?CHECK\s*\(\s*JSON_VALID\s*\(\s*(' . self::IDENT . ')\s*\)\s*\)/i', $action, $m) === 1) {
                $column = self::quote(self::ident($m[1]));
                $statements[] = $alter('ALTER COLUMN ' . $column . ' TYPE jsonb USING ' . $column . '::jsonb');

                continue;
            }
            if (preg_match('/^DROP\s+(?:CHECK|CONSTRAINT)\s+(' . self::IDENT . ')$/iD', $action, $m) === 1) {
                $statements[] = $alter('DROP CONSTRAINT IF EXISTS ' . self::quote(self::ident($m[1])));

                continue;
            }
            if (preg_match('/^DROP\s+FOREIGN\s+KEY\s+(' . self::IDENT . ')$/iD', $action, $m) === 1) {
                $statements[] = $alter('DROP CONSTRAINT ' . self::quote(self::ident($m[1])));

                continue;
            }
            if (preg_match('/^DROP\s+PRIMARY\s+KEY$/iD', $action) === 1) {
                $statements[] = $alter('DROP CONSTRAINT ' . self::quote($table . '_pkey'));

                continue;
            }
            if (preg_match('/^DROP\s+(?:INDEX|KEY)\s+(' . self::IDENT . ')$/iD', $action, $m) === 1) {
                $statements[] = 'DROP INDEX IF EXISTS ' . self::quote(self::indexName($table, self::ident($m[1])));

                continue;
            }
            if (preg_match('/^DROP\s+(?:COLUMN\s+)?((?:IF\s+EXISTS\s+)?' . self::IDENT . ')$/iD', $action, $m) === 1 && preg_match('/^(?:PRIMARY|FOREIGN|CONSTRAINT|CHECK|INDEX|KEY)\b/i', $m[1]) !== 1) {
                $statements[] = $alter('DROP COLUMN ' . preg_replace_callback('/' . self::IDENT . '$/D', static fn (array $i): string => self::quote(self::ident($i[0])), $m[1]));

                continue;
            }
            if (preg_match('/^RENAME\s+(?:INDEX|KEY)\s+(' . self::IDENT . ')\s+TO\s+(' . self::IDENT . ')$/iD', $action, $m) === 1) {
                $statements[] = 'ALTER INDEX IF EXISTS ' . self::quote(self::indexName($table, self::ident($m[1]))) . ' RENAME TO ' . self::quote(self::indexName($table, self::ident($m[2])));

                continue;
            }
            if (preg_match('/^RENAME\s+COLUMN\s+(' . self::IDENT . ')\s+TO\s+(' . self::IDENT . ')$/iD', $action, $m) === 1) {
                $statements[] = $alter('RENAME COLUMN ' . self::quote(self::ident($m[1])) . ' TO ' . self::quote(self::ident($m[2])));

                continue;
            }
            if (preg_match('/^RENAME\s+(?:TO\s+|AS\s+)?(' . self::IDENT . ')$/iD', $action, $m) === 1) {
                $statements[] = $alter('RENAME TO ' . self::quote(self::ident($m[1])));

                continue;
            }
            if (preg_match('/^ADD\s+(?:CONSTRAINT\s+(' . self::IDENT . ')\s+)?PRIMARY\s+KEY\s*\(([^)]*)\)/i', $action, $m) === 1) {
                $statements[] = $alter('ADD PRIMARY KEY (' . self::columnList($m[2], false) . ')');

                continue;
            }
            if (preg_match('/^ADD\s+(?:CONSTRAINT\s+(?:(' . self::IDENT . ')\s+)?)?UNIQUE(?:\s+(?:KEY|INDEX))?\s*(' . self::IDENT . ')?\s*\((.*)\)\s*(?:USING\s+\w+)?$/isD', $action, $m) === 1) {
                $names = self::columnNames($m[3]);
                $name = $m[2] !== '' ? self::ident($m[2]) : ($m[1] !== '' ? self::ident($m[1]) : $names[0]);
                $statements[] = $this->createIndex($table, $name, $m[3], true);

                continue;
            }
            if (preg_match('/^ADD\s+(?:KEY|INDEX)\s*(' . self::IDENT . ')?\s*\((.*)\)\s*(?:USING\s+\w+)?$/isD', $action, $m) === 1) {
                $statements[] = $this->createIndex($table, $m[1] !== '' ? self::ident($m[1]) : self::columnNames($m[2])[0], $m[2], false);

                continue;
            }
            if (preg_match('/^ADD\s+(?:FULLTEXT|SPATIAL)\b/i', $action) === 1) {
                continue;
            }
            if (preg_match('/^ADD\s+(?:CONSTRAINT\s+(' . self::IDENT . ')\s+)?FOREIGN\s+KEY\s*(?:' . self::IDENT . '\s*)?\(([^)]*)\)\s*(REFERENCES\s+.*)$/isD', $action, $m) === 1) {
                // the number MySQL would pick depends on the existing constraints, the column names keep the name unique
                $name = $m[1] !== '' ? self::ident($m[1]) : $table . '_ibfk_' . implode('_', self::columnNames($m[2]));
                $columns = self::columnNames($m[2]);
                $statements[] = $alter('ADD CONSTRAINT ' . self::quote($name) . ' FOREIGN KEY (' . self::columnList($m[2], false) . ') ' . self::foreignKeyTarget($m[3]));
                // MySQL creates the index for the foreign key only when no index starts with its columns
                $statements[] = 'DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_index i WHERE i.indrelid = to_regclass(' . self::literal(self::quote($table)) . ')'
                    . ' AND (ARRAY(SELECT unnest(i.indkey::int2[])))[1:' . \count($columns) . '] = ' . self::keyArray($table, $columns) . ') THEN '
                    . 'CREATE INDEX IF NOT EXISTS ' . self::quote(self::indexName($table, $name)) . ' ON ' . self::quote($table) . ' (' . implode(', ', array_map(self::quote(...), $columns)) . '); '
                    . 'COMMENT ON INDEX ' . self::quote(self::indexName($table, $name)) . ' IS ' . self::literal(self::IMPLICIT_FK_INDEX) . '; END IF; END $$';

                continue;
            }
            if (preg_match('/^ADD\s+CONSTRAINT\b/i', $action) === 1) {
                $statements[] = $alter($action);

                continue;
            }
            if (preg_match('/^ADD\s+(?:COLUMN\s+)?(.*)$/isD', $action, $m) === 1) {
                $definitions = trim($m[1]);
                $definitions = $definitions !== '' && $definitions[0] === '(' ? substr($definitions, 1, (self::matchParen($definitions, 0) ?? \strlen($definitions)) - 1) : $definitions;
                foreach (self::splitTopLevel($definitions) as $definition) {
                    $column = $this->parseColumn(trim($definition), $table, [], 'utf8mb4_unicode_ci');
                    $statements[] = $alter('ADD COLUMN ' . $this->renderColumn($column));
                    if (isset($column['comment'])) {
                        $statements[] = 'COMMENT ON COLUMN ' . self::quote($table) . '.' . self::quote($column['name']) . ' IS ' . $column['comment'];
                    }
                    if ($column['unique']) {
                        $statements[] = $this->createIndex($table, $column['name'], self::quote($column['name']), true);
                    }
                }

                continue;
            }
            if (preg_match('/^(MODIFY|CHANGE)\s+(?:COLUMN\s+)?(.*)$/isD', $action, $m) === 1) {
                $definition = $m[2];
                $old = null;
                if (strtoupper($m[1]) === 'CHANGE' && preg_match('/^(' . self::IDENT . ')\s+(.*)$/sD', $definition, $c) === 1) {
                    $old = self::ident($c[1]);
                    $definition = $c[2];
                }

                $column = $this->parseColumn(trim($definition), $table, [], 'utf8mb4_unicode_ci');
                $current = $old ?? $column['name'];
                if ($old !== null && $old !== $column['name']) {
                    $statements[] = $alter('RENAME COLUMN ' . self::quote($old) . ' TO ' . self::quote($column['name']));
                    $current = $column['name'];
                }

                array_push($statements, ...$this->modifyColumn($table, $current, $column));

                continue;
            }
            if (preg_match('/^ALTER\s+(?:COLUMN\s+)?(' . self::IDENT . ')\s+(SET\s+DEFAULT|DROP\s+DEFAULT)\s*(.*)$/isD', $action, $m) === 1) {
                $default = $m[3];
                // MySQL has no boolean type, a tinyint(1) default of 0 / 1 is false / true here
                if (strtoupper($m[2]) === 'SET DEFAULT' && $this->columnTypeLookup !== null && ($this->columnTypeLookup)($table, self::ident($m[1])) === 'boolean') {
                    $default = match (trim($default, '()\'" ')) {
                        '1' => 'true',
                        '0' => 'false',
                        default => $default,
                    };
                }
                $statements[] = $alter('ALTER COLUMN ' . self::quote(self::ident($m[1])) . ' ' . strtoupper($m[2]) . ($default !== '' ? ' ' . $default : ''));

                continue;
            }

            $statements[] = $alter($action);
        }

        return $statements;
    }

    /**
     * MODIFY / CHANGE replace the full definition: type, nullability and default
     *
     * @param array{name: string, type: string, collate: ?string, notNull: ?bool, default: ?string, identity: bool, generated: ?string, primary: bool, unique: bool, textLike: bool, extra?: string, comment?: string} $column
     *
     * @return list<string>
     */
    private function modifyColumn(string $table, string $name, array $column): array
    {
        $alter = 'ALTER TABLE ' . self::quote($table) . ' ALTER COLUMN ' . self::quote($name) . ' ';
        $type = $column['type'];

        // a generated column can not change its expression in place, it holds no data when virtual, so it is dropped and added again
        if ($column['generated'] !== null) {
            return [
                'ALTER TABLE ' . self::quote($table) . ' DROP COLUMN ' . self::quote($name),
                'ALTER TABLE ' . self::quote($table) . ' ADD COLUMN ' . $this->renderColumn($column),
            ];
        }

        // a JSON column stays jsonb when it is modified as LONGTEXT, MariaDB keeps the CHECK constraint
        if ($column['textLike'] && $this->columnTypeLookup !== null && ($this->columnTypeLookup)($table, $name) === 'jsonb') {
            $type = 'jsonb';
        }

        $using = match (true) {
            $type === 'boolean' => ' USING (' . self::quote($name) . '::integer <> 0)',
            $type === 'uuid' => '',
            $type === 'smallint' => ' USING ' . self::quote($name) . '::integer::smallint',
            default => ' USING ' . self::quote($name) . '::' . preg_replace('/\(.*$/D', '', $type),
        };
        if ($type === 'jsonb' || $type === 'text' || str_starts_with($type, 'varchar') || str_starts_with($type, 'char')) {
            $using = ' USING ' . self::quote($name) . '::' . ($type === 'jsonb' ? 'jsonb' : 'text');
        }

        // MySQL turns a generated column into a plain one with MODIFY
        $statements = [$this->dropGeneration($table, $name, $type, $column['collate']), $alter . 'DROP DEFAULT', $alter . 'TYPE ' . $type . ($column['collate'] !== null ? ' COLLATE ' . $column['collate'] : '') . $using];
        // MySQL keeps the columns of a primary key NOT NULL, PostgreSQL refuses to drop it
        $statements[] = $column['notNull'] === true ? $alter . 'SET NOT NULL' : 'DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_index i JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey) WHERE i.indisprimary AND i.indrelid = to_regclass(\'' . str_replace('\'', '\'\'', self::quote($table)) . '\') AND a.attname = \'' . str_replace('\'', '\'\'', $name) . '\') THEN ' . $alter . 'DROP NOT NULL; END IF; END $$';
        $statements[] = $alter . ($column['default'] !== null ? 'SET DEFAULT ' . $column['default'] : 'DROP DEFAULT');
        if ($column['unique']) {
            $statements[] = $this->createIndex($table, $name, self::quote($name), true);
        }

        return $statements;
    }

    /**
     * MODIFY turns a generated column into a plain one.
     * PostgreSQL can only drop the expression of a stored column, a virtual column is dropped and added again (it holds no data).
     */
    private function dropGeneration(string $table, string $name, string $type, ?string $collate): string
    {
        $lookup = 'SELECT a.attgenerated FROM pg_attribute a WHERE a.attrelid = to_regclass(' . self::literal(self::quote($table)) . ') AND a.attname = ' . self::literal($name) . ' AND NOT a.attisdropped';
        $table = self::quote($table);
        $column = self::quote($name);

        return 'DO $$ DECLARE g "char"; BEGIN ' . $lookup . ' INTO g; '
            . 'IF g = \'v\' THEN ALTER TABLE ' . $table . ' DROP COLUMN ' . $column . '; ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $type . ($collate !== null ? ' COLLATE ' . $collate : '') . '; '
            . 'ELSIF g = \'s\' THEN ALTER TABLE ' . $table . ' ALTER COLUMN ' . $column . ' DROP EXPRESSION; END IF; END $$';
    }

    private static function literal(string $value): string
    {
        return '\'' . str_replace('\'', '\'\'', $value) . '\'';
    }

    private function createIndex(string $table, string $name, string $columns, bool $unique): string
    {
        $create = 'CREATE ' . ($unique ? 'UNIQUE ' : '') . 'INDEX IF NOT EXISTS ' . self::quote(self::indexName($table, $name)) . ' ON ' . self::quote($table) . ' (' . self::columnList($columns, true) . ')';

        // PostgreSQL can not index a virtual generated column, MySQL can: the column is rebuilt as STORED, which is what the schema script emits for indexed columns as well
        $names = 'ARRAY[' . implode(', ', array_map(self::literal(...), self::columnNames($columns))) . ']';

        return 'DO $$ DECLARE r record; BEGIN'
            . ' FOR r IN SELECT a.attname::text AS name, format_type(a.atttypid, a.atttypmod) AS type, pg_get_expr(d.adbin, d.adrelid) AS expr'
            . ' FROM pg_attribute a JOIN pg_attrdef d ON d.adrelid = a.attrelid AND d.adnum = a.attnum'
            . ' WHERE a.attrelid = to_regclass(' . self::literal(self::quote($table)) . ') AND a.attgenerated = \'v\' AND a.attname::text = ANY(' . $names . ') LOOP'
            . ' EXECUTE format(\'ALTER TABLE %s DROP COLUMN %I\', ' . self::literal(self::quote($table)) . ', r.name);'
            . ' EXECUTE format(\'ALTER TABLE %s ADD COLUMN %I %s GENERATED ALWAYS AS (%s) STORED\', ' . self::literal(self::quote($table)) . ', r.name, r.type, r.expr);'
            . ' END LOOP; ' . $create . ';'
            // MySQL drops the index it created for a foreign key as soon as another index covers the same leading columns
            . ' FOR r IN SELECT ic.relname::text AS name FROM pg_constraint c'
            . ' JOIN pg_class ic ON ic.relname = (CASE WHEN length(' . self::literal($table . '.') . ' || c.conname) <= 63 THEN ' . self::literal($table . '.') . ' || c.conname ELSE substr(' . self::literal($table . '.') . ' || c.conname, 1, 54) || \'_\' || substr(md5(' . self::literal($table . '.') . ' || c.conname), 1, 8) END)'
            . ' JOIN pg_index i ON i.indexrelid = ic.oid'
            . ' WHERE c.contype = \'f\' AND c.conrelid = to_regclass(' . self::literal(self::quote($table)) . ') AND i.indrelid = c.conrelid AND NOT i.indisunique AND NOT i.indisprimary'
            . ' AND obj_description(ic.oid, \'pg_class\') = ' . self::literal(self::IMPLICIT_FK_INDEX)
            . ' AND ic.relname::text <> ' . self::literal(self::indexName($table, $name))
            . ' AND (SELECT array_length(ARRAY(SELECT unnest(i.indkey::int2[])), 1)) <= ' . \count(self::columnNames($columns))
            . ' AND ARRAY(SELECT unnest(i.indkey::int2[])) = (' . self::keyArray($table, self::columnNames($columns)) . ')[1:(SELECT array_length(ARRAY(SELECT unnest(i.indkey::int2[])), 1))]'
            . ' LOOP EXECUTE format(\'DROP INDEX %I\', r.name); END LOOP; END $$';
    }

    /**
     * SQL expression for the attribute numbers of the columns, in the given order
     *
     * @param list<string> $columns
     */
    private static function keyArray(string $table, array $columns): string
    {
        return 'ARRAY(SELECT a.attnum FROM unnest(ARRAY[' . implode(', ', array_map(self::literal(...), $columns)) . ']) WITH ORDINALITY u(n, o)'
            . ' JOIN pg_attribute a ON a.attrelid = to_regclass(' . self::literal(self::quote($table)) . ') AND a.attname = u.n ORDER BY u.o)';
    }

    /**
     * @param array<string, bool> $jsonColumns
     *
     * @return array{name: string, type: string, collate: ?string, notNull: ?bool, default: ?string, identity: bool, generated: ?string, primary: bool, unique: bool, textLike: bool, extra?: string, comment?: string}
     */
    private function parseColumn(string $definition, string $table, array $jsonColumns, string $tableCollation): array
    {
        // `DOUBLE PRECISION` (the DBAL MySQL platform writes it for float columns) is the type `double`
        if (preg_match('/^(' . self::IDENT . ')\s+([A-Za-z]+)(?:\s+PRECISION\b)?\s*(?:\(([^)]*)\))?((?:\s+(?:UNSIGNED|SIGNED|ZEROFILL))*)\s*(.*)$/isD', $definition, $m) !== 1) {
            throw AdapterException::unparsableColumnDefinition($definition);
        }

        $name = self::ident($m[1]);
        $dataType = strtolower($m[2]);
        $args = trim($m[3]);
        $unsigned = stripos($m[4], 'unsigned') !== false;
        $rest = $m[5];

        $column = ['name' => $name, 'type' => '', 'collate' => null, 'notNull' => null, 'default' => null, 'identity' => false, 'generated' => null, 'primary' => false, 'unique' => false, 'textLike' => false];
        $collation = null;
        $rawDefault = null;
        $generatedKind = 'VIRTUAL';
        $inlineJson = false;
        $extra = [];

        $length = \strlen($rest);
        $i = 0;
        while ($i < $length) {
            $tail = substr($rest, $i);
            if (preg_match('/^\s+/', $tail, $w) === 1) {
                $i += \strlen($w[0]);

                continue;
            }
            if (preg_match('/^NOT\s+NULL\b/i', $tail, $w) === 1) {
                $column['notNull'] = true;
            } elseif (preg_match('/^NULL\b/i', $tail, $w) === 1) {
                $column['notNull'] = false;
            } elseif (preg_match('/^DEFAULT\s+/i', $tail, $w) === 1) {
                $start = $i + \strlen($w[0]);
                [$rawDefault, $end] = $this->readValue($rest, $start);
                $i = $end;

                continue;
            } elseif (preg_match('/^COLLATE\s*=?\s*[\x27"]?(\w+)[\x27"]?/i', $tail, $w) === 1) {
                $collation = strtolower($w[1]);
            } elseif (preg_match('/^(?:CHARACTER\s+SET|CHARSET)\s*=?\s*[\x27"]?\w+[\x27"]?/i', $tail, $w) === 1 || preg_match('/^(?:ON\s+UPDATE\s+\w+(?:\([^)]*\))?|INVISIBLE|VISIBLE|ZEROFILL|FIRST\b|AFTER\s+' . self::IDENT . ')/i', $tail, $w) === 1) {
                // dropped
            } elseif (preg_match('/^(UNSIGNED|SIGNED)\b/i', $tail, $w) === 1) {
                // after the attributes, MySQL accepts it in any order
                $unsigned = $unsigned || strtoupper($w[1]) === 'UNSIGNED';
            } elseif (preg_match('/^COMMENT\s+/i', $tail, $w) === 1) {
                [$comment, $end] = $this->readValue($rest, $i + \strlen($w[0]));
                if (str_starts_with($comment, '\'')) {
                    // kept as COMMENT ON COLUMN, the DBAL schema manager reads it back (custom entities mark their columns with it)
                    $column['comment'] = $comment;
                }
                $i = $end;

                continue;
            } elseif (preg_match('/^AUTO_INCREMENT\b/i', $tail, $w) === 1) {
                $column['identity'] = true;
            } elseif (preg_match('/^PRIMARY\s+KEY\b/i', $tail, $w) === 1) {
                $column['primary'] = true;
                $extra[] = 'PRIMARY KEY';
            } elseif (preg_match('/^UNIQUE(?:\s+KEY)?\b/i', $tail, $w) === 1) {
                $column['unique'] = true;
            } elseif (preg_match('/^(?:GENERATED\s+ALWAYS\s+)?AS\s*\(/i', $tail, $w) === 1) {
                $open = $i + \strlen($w[0]) - 1;
                $close = self::matchParen($rest, $open) ?? ($length - 1);
                $column['generated'] = substr($rest, $open + 1, $close - $open - 1);
                $i = $close + 1;
                if (preg_match('/^\s*(VIRTUAL|STORED|PERSISTENT)\b/i', substr($rest, $i), $k) === 1) {
                    $generatedKind = strtoupper($k[1]) === 'VIRTUAL' ? 'VIRTUAL' : 'STORED';
                    $i += \strlen($k[0]);
                }

                continue;
            } elseif (preg_match('/^CHECK\s*\(/i', $tail, $w) === 1) {
                $open = $i + \strlen($w[0]) - 1;
                $close = self::matchParen($rest, $open) ?? ($length - 1);
                $inner = substr($rest, $open + 1, $close - $open - 1);
                if (preg_match('/^\s*JSON_VALID\s*\(/i', $inner) === 1) {
                    $inlineJson = true;
                } else {
                    $extra[] = 'CHECK (' . $inner . ')';
                }
                $i = $close + 1;

                continue;
            } else {
                preg_match('/^\S+/', $tail, $w);
                $w[0] ??= '';
                $extra[] = $w[0];
            }
            $i += \strlen($w[0]);
        }

        $type = $this->mapType($table, $name, $dataType, $args, $unsigned, isset($jsonColumns[$name]) || $inlineJson, $column);
        $column['type'] = $type;
        $column['collate'] = \in_array($type, ['text', 'jsonb'], true) || str_starts_with($type, 'varchar') || str_starts_with($type, 'char')
            ? (($collation ?? $tableCollation) === 'utf8mb4_unicode_ci' && $type !== 'jsonb' ? '"ci"' : null)
            : null;
        if ($column['textLike'] === false && $type !== 'jsonb' && !str_starts_with($type, 'varchar') && !str_starts_with($type, 'char') && $type !== 'text') {
            $column['collate'] = null;
        }

        if ($rawDefault !== null) {
            $column['default'] = $this->mapDefault($rawDefault, $type);
        }
        // jsonb_build_object / jsonb_build_array are only STABLE, PostgreSQL requires an immutable generation expression, the column becomes a plain one
        if ($column['generated'] !== null && preg_match('/\bjson(?:b_build)?_(?:object|array)\s*\(/i', $column['generated']) === 1) {
            $column['generated'] = null;
        }

        if ($column['generated'] !== null) {
            $column['generated'] = $this->mapGeneratedExpression($column['generated'], $type) . ') ' . ($generatedKind === 'STORED' ? 'STORED' : 'VIRTUAL');
            $column['default'] = null;
        }
        if ($extra !== []) {
            $column['extra'] = implode(' ', $extra);
        }

        return $column;
    }

    /**
     * @param array{name: string, type: string, collate: ?string, notNull: ?bool, default: ?string, identity: bool, generated: ?string, primary: bool, unique: bool, textLike: bool, extra?: string, comment?: string} $column
     */
    private function mapType(string $table, string $name, string $dataType, string $args, bool $unsigned, bool $json, array &$column): string
    {
        $compact = preg_replace('/\s+/', '', $args) ?? '';

        if ($dataType === 'binary' && $compact === '16') {
            return 'uuid';
        }
        if ($dataType === 'tinyint' && $compact === '1') {
            return 'boolean';
        }
        if ($dataType === 'bool' || $dataType === 'boolean') {
            return 'boolean';
        }
        if (\in_array($dataType, ['tinyint', 'smallint'], true)) {
            return 'smallint';
        }
        if (\in_array($dataType, ['int', 'integer', 'mediumint'], true)) {
            return $unsigned ? 'bigint' : 'integer';
        }
        if ($dataType === 'bigint') {
            return 'bigint';
        }
        if (\in_array($dataType, ['varchar', 'char'], true)) {
            $column['textLike'] = true;

            // CHAR(n) becomes varchar(n): MySQL removes the trailing pad spaces of a CHAR value on read, PostgreSQL's character(n) returns them
            // (`currency.iso_code` 'FO' came back as 'FO ')
            return 'varchar(' . ($compact !== '' ? $compact : '1') . ')';
        }
        if (\in_array($table . '.' . $name, self::SERIALIZED_TEXT_COLUMNS, true)) {
            return 'bytea';
        }
        if ($dataType === 'json') {
            return 'jsonb';
        }
        if (\in_array($dataType, ['text', 'mediumtext', 'longtext', 'tinytext'], true)) {
            $column['textLike'] = true;

            return $json ? 'jsonb' : 'text';
        }
        if (\in_array($dataType, ['longblob', 'mediumblob', 'blob', 'tinyblob', 'varbinary', 'binary'], true)) {
            return 'bytea';
        }
        if (\in_array($dataType, ['datetime', 'timestamp'], true)) {
            return 'timestamp(' . ($compact !== '' ? $compact : '0') . ')';
        }
        if ($dataType === 'date') {
            return 'date';
        }
        if ($dataType === 'time') {
            return 'time' . ($compact !== '' ? '(' . $compact . ')' : '');
        }
        if ($dataType === 'double') {
            return 'double precision';
        }
        if ($dataType === 'float') {
            return 'real';
        }
        if (\in_array($dataType, ['decimal', 'numeric'], true)) {
            return 'numeric' . ($compact !== '' ? '(' . $compact . ')' : '');
        }
        if ($dataType === 'enum' || $dataType === 'set') {
            $column['textLike'] = true;

            return 'text';
        }

        throw AdapterException::unmappedColumnType($dataType, $table, $name);
    }

    private function mapDefault(string $value, string $type): ?string
    {
        $trimmed = trim($value);
        $upper = strtoupper($trimmed);

        if ($upper === 'NULL') {
            // a nullable column is NULL without a default, an explicit DEFAULT NULL only shows up as noise in the catalog
            return null;
        }
        if (preg_match('/^(?:CURRENT_TIMESTAMP|NOW|LOCALTIMESTAMP)\b/i', $trimmed) === 1) {
            return 'CURRENT_TIMESTAMP';
        }
        if ($type === 'boolean') {
            return \in_array(strtolower(trim($trimmed, '()\'"')), ['1', 'b1', 'b\'1\'', 'true'], true) || $trimmed === 'b\'1\'' ? 'true' : 'false';
        }
        if ($type === 'uuid') {
            // the translator already turned 0x... into '<hex>'::uuid
            if (preg_match('/^(?:0x|UNHEX\s*\(\s*\'|X\'|\')([0-9a-f]{32})/i', $trimmed, $m) === 1) {
                return '\'' . strtolower(substr($m[1], 0, 8) . '-' . substr($m[1], 8, 4) . '-' . substr($m[1], 12, 4) . '-' . substr($m[1], 16, 4) . '-' . substr($m[1], 20)) . '\'';
            }

            return null;
        }
        if ($type === 'jsonb') {
            $inner = strtoupper(preg_replace('/\s+/', '', $trimmed) ?? '');
            if (preg_match('/^\(*(?:JSON_OBJECT|JSONB_BUILD_OBJECT)\(\)\)*$/D', $inner) === 1) {
                return '\'{}\'';
            }
            if (preg_match('/^\(*(?:JSON_ARRAY|JSONB_BUILD_ARRAY)\(\)\)*$/D', $inner) === 1) {
                return '\'[]\'';
            }
        }
        if (preg_match('/^\((.*)\)$/sD', $trimmed, $m) === 1 && self::matchParen($trimmed, 0) === \strlen($trimmed) - 1) {
            return '(' . $m[1] . ')';
        }
        if ($type === 'bytea' && $trimmed === '\'\'') {
            return '\'\'';
        }

        return $trimmed;
    }

    /**
     * Translates the generation expressions that exist in the core, everything else is passed on unchanged and fails visibly.
     */
    private function mapGeneratedExpression(string $expression, string $type): string
    {
        $expression = trim($expression);
        if ($type === 'uuid') {
            // a binary literal next to UNHEX(...), e.g. COALESCE(UNHEX(x), 0x0), is a uuid here
            $expression = preg_replace_callback('/\b0x([0-9a-fA-F]{1,32})\b/', static fn (array $m): string => '\'' . strtolower(preg_replace('/^(.{8})(.{4})(.{4})(.{4})(.{12})$/D', '$1-$2-$3-$4-$5', str_pad($m[1], 32, '0', \STR_PAD_LEFT)) ?? '') . '\'::uuid', $expression) ?? $expression;
        }
        $cast = match ($type) {
            'double precision' => '::double precision',
            'integer' => '::integer',
            'bigint' => '::bigint',
            'real' => '::real',
            default => str_starts_with($type, 'numeric') ? '::' . $type : '',
        };

        $expression = preg_replace_callback(
            '/(?:JSON_UNQUOTE\s*\(\s*JSON_EXTRACT|JSON_VALUE)\s*\(\s*(' . self::IDENT . ')\s*,\s*\'\$\.([\w.]+)\'\s*\)(?:\s*\))?/i',
            static function (array $m) use ($cast): string {
                $path = explode('.', $m[2]);
                $accessor = \count($path) === 1 ? '->>\'' . $path[0] . '\'' : '#>>\'{' . implode(',', $path) . '}\'';

                return '(' . $m[1] . $accessor . ')' . $cast;
            },
            $expression
        ) ?? $expression;

        $expression = preg_replace('/\bCONVERT\s*\(\s*(' . self::IDENT . ')\s*,\s*DATE\s*\)/i', 'CAST($1 AS date)', $expression) ?? $expression;
        $expression = preg_replace('/\bIF\s*\(\s*(' . self::IDENT . ')\s*=\s*1\s*,\s*(' . self::IDENT . ')\s*,\s*NULL\s*\)/i', 'CASE WHEN $1 THEN $2 END', $expression) ?? $expression;

        return $expression;
    }

    /**
     * @param array<string, mixed> $column
     */
    private function renderColumn(array $column): string
    {
        $sql = self::quote($column['name']) . ' ' . $column['type'];
        if ($column['collate'] !== null) {
            $sql .= ' COLLATE ' . $column['collate'];
        }
        if ($column['generated'] !== null) {
            return $sql . ' GENERATED ALWAYS AS (' . $column['generated'] . ($column['notNull'] === true ? ' NOT NULL' : '');
        }
        if ($column['identity']) {
            $sql .= ' GENERATED BY DEFAULT AS IDENTITY';
        }
        if ($column['notNull'] === true) {
            $sql .= ' NOT NULL';
        }
        if ($column['default'] !== null && !$column['identity']) {
            $sql .= ' DEFAULT ' . $column['default'];
        }
        if (isset($column['extra'])) {
            $sql .= ' ' . $column['extra'];
        }

        return $sql;
    }

    /**
     * Reads a default / comment value: string literal, parenthesized expression or a word with optional arguments
     *
     * @return array{string, int}
     */
    private function readValue(string $s, int $start): array
    {
        $length = \strlen($s);
        $i = $start;
        if (preg_match('/^[EeBbXx]?\'/', substr($s, $i, 2)) === 1 || ($s[$i] ?? '') === '\'') {
            $i += $s[$i] === '\'' ? 0 : 1;
            for ($i++; $i < $length; ++$i) {
                if ($s[$i] === '\\') {
                    ++$i;

                    continue;
                }
                if ($s[$i] === '\'') {
                    if (($s[$i + 1] ?? '') === '\'') {
                        ++$i;

                        continue;
                    }

                    break;
                }
            }

            return [substr($s, $start, $i + 1 - $start), $i + 1];
        }
        if (($s[$i] ?? '') === '(') {
            $close = self::matchParen($s, $i) ?? ($length - 1);

            return [substr($s, $i, $close - $i + 1), $close + 1];
        }

        preg_match('/^[^\s(]+(?:\([^)]*\))?/', substr($s, $i), $m);
        $value = $m[0] ?? '';

        return [$value, $i + \strlen($value)];
    }

    private static function foreignKeyTarget(string $references): string
    {
        // MATCH FULL / SIMPLE and the referential actions are the same on both, only the table and column quoting needs care
        $target = preg_replace_callback(
            '/^REFERENCES\s+(' . self::IDENT . ')\s*\(([^)]*)\)/i',
            static fn (array $m): string => 'REFERENCES ' . self::quote(self::ident($m[1])) . ' (' . self::columnList($m[2], false) . ')',
            trim($references)
        ) ?? $references;

        // MySQL restricts when no action is given, PostgreSQL would use NO ACTION
        foreach (['DELETE', 'UPDATE'] as $event) {
            if (preg_match('/\bON\s+' . $event . '\b/i', $target) !== 1) {
                $target .= ' ON ' . $event . ' RESTRICT';
            }
        }

        return $target;
    }

    /**
     * @return list<string>
     */
    private static function columnNames(string $columns): array
    {
        $names = [];
        foreach (self::splitTopLevel($columns) as $part) {
            if (preg_match('/^\s*(' . self::IDENT . ')/', $part, $m) === 1) {
                $names[] = self::ident($m[1]);
            }
        }

        return $names;
    }

    /**
     * Quotes the names, drops MySQL prefix lengths `col(10)`, keeps ASC / DESC for indexes
     */
    private static function columnList(string $columns, bool $keepOrder): string
    {
        $out = [];
        foreach (self::splitTopLevel($columns) as $part) {
            if (preg_match('/^\s*(' . self::IDENT . ')\s*(?:\(\s*\d+\s*\))?\s*(ASC|DESC)?\s*$/iD', $part, $m) !== 1) {
                $out[] = trim($part);

                continue;
            }
            $out[] = self::quote(self::ident($m[1])) . ($keepOrder && ($m[2] ?? '') !== '' ? ' ' . strtoupper($m[2]) : '');
        }

        return implode(', ', $out);
    }

    private static function ident(string $raw): string
    {
        $raw = trim($raw);
        if ($raw !== '' && $raw[0] === '"') {
            return str_replace('""', '"', substr($raw, 1, -1));
        }

        return strtolower($raw);
    }

    private static function quote(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }

    private static function matchParen(string $s, int $open): ?int
    {
        $depth = 0;
        $length = \strlen($s);
        for ($i = $open; $i < $length; ++$i) {
            $char = $s[$i];
            $skipped = self::skipLiteralOrComment($s, $i);
            if ($skipped !== null) {
                $i = $skipped;

                continue;
            }
            if ($char === '(') {
                ++$depth;
            } elseif ($char === ')' && --$depth === 0) {
                return $i;
            }
        }

        return null;
    }

    /**
     * @return int|null index of the last character of the string, quoted identifier or comment that starts at $i, null when none starts there
     */
    private static function skipLiteralOrComment(string $s, int $i): ?int
    {
        $char = $s[$i];
        if ($char === '\'' || $char === '"' || $char === '`') {
            return self::skipQuoted($s, $i);
        }
        $next = $s[$i + 1] ?? '';
        if (($char === '-' && $next === '-') || $char === '#') {
            $end = strpos($s, "\n", $i);

            return $end === false ? \strlen($s) : $end;
        }
        if ($char === '/' && $next === '*') {
            $end = strpos($s, '*/', $i + 2);

            return $end === false ? \strlen($s) : $end + 1;
        }

        return null;
    }

    private static function skipQuoted(string $s, int $start): int
    {
        $quote = $s[$start];
        $length = \strlen($s);
        for ($i = $start + 1; $i < $length; ++$i) {
            if ($quote === '\'' && $s[$i] === '\\') {
                ++$i;

                continue;
            }
            if ($s[$i] === $quote) {
                if (($s[$i + 1] ?? '') === $quote) {
                    ++$i;

                    continue;
                }

                return $i;
            }
        }

        return $length;
    }
}

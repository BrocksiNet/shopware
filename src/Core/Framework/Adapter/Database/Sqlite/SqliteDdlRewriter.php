<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Adapter\Database\Sqlite;

use Shopware\Core\Framework\Adapter\Database\PostgreSQL\DdlRewriter;
use Shopware\Core\Framework\Log\Package;

/**
 * Small rule set that turns MySQL DDL into SQLite DDL (spike).
 * SQLite accepts the MySQL type names and backtick identifiers, so only the syntax it does not know is rewritten:
 * table options, KEY / UNIQUE KEY inside CREATE TABLE, AUTO_INCREMENT, ON UPDATE, COMMENT, AFTER / FIRST and multi action ALTER TABLE.
 * Everything SQLite can not express (MODIFY, CHANGE, ADD / DROP CONSTRAINT, ADD FOREIGN KEY) is reported through the blocker callback and skipped.
 *
 * @internal
 */
#[Package('framework')]
class SqliteDdlRewriter
{
    /**
     * @var list<string>
     */
    public static array $blockers = [];

    public static string $last = '';

    /**
     * @param \Closure(string): mixed|null $fetchOne runs a query on the connection and returns the first column, needed to patch the stored table definition
     */
    public function __construct(private readonly ?\Closure $fetchOne = null)
    {
    }

    /**
     * @return list<string|\Closure>
     */
    public function rewrite(string $sql): array
    {
        self::$last = $sql;
        $out = [];
        foreach (DdlRewriter::splitTopLevel($sql, ';') as $statement) {
            $statement = trim($statement);
            if ($statement === '') {
                continue;
            }
            array_push($out, ...$this->rewriteStatement($statement));
        }

        return $out;
    }

    /**
     * @return list<string|\Closure>
     */
    private function rewriteStatement(string $sql): array
    {
        if (preg_match('/^SET\s+(?:SESSION\s+)?(?:@@(?:SESSION\.)?)?foreign_key_checks\s*=\s*(0|1|ON|OFF)\s*;?$/i', $sql, $m)) {
            // `PRAGMA foreign_keys` is ignored inside a transaction, where the check is deferred to the commit instead (a rollback skips it)
            return \in_array(strtoupper($m[1]), ['0', 'OFF'], true)
                ? ['PRAGMA foreign_keys = OFF', 'PRAGMA defer_foreign_keys = ON']
                : ['PRAGMA defer_foreign_keys = OFF', 'PRAGMA foreign_keys = ON'];
        }
        if (preg_match('/^SET\s+(?:SESSION\s+|GLOBAL\s+)?(?:default_storage_engine|foreign_key_checks|unique_checks|sql_mode|sql_notes|group_concat_max_len|innodb_\w+)\b/i', $sql)) {
            return ['SELECT 1'];
        }
        if (preg_match('/^CREATE\s+(?:TEMPORARY\s+)?TABLE/i', $sql)) {
            return $this->createTable($sql);
        }
        if (preg_match('/^ALTER\s+TABLE\s+(`?\w+`?)\s+(.*)$/is', $sql, $m)) {
            return $this->alterTable($m[1], $m[2]);
        }
        if (preg_match('/^DROP\s+TABLE\s+(IF\s+EXISTS\s+)?(.+,.+)$/is', $sql, $m)) {
            // MySQL drops several tables with one statement
            return array_map(static fn (string $t): string => 'DROP TABLE ' . $m[1] . trim($t), DdlRewriter::splitTopLevel($m[2]));
        }
        if (preg_match('/^DROP\s+TRIGGER\s+(?!IF\b)/i', $sql)) {
            // the version and rounding triggers of the MySQL migrations do not exist in SQLite
            return [(string) preg_replace('/^DROP\s+TRIGGER\s+/i', 'DROP TRIGGER IF EXISTS ', $sql)];
        }
        if (preg_match('/^DROP\s+INDEX\s+`?([\w.]+)`?\s+ON\s+`?(\w+)`?$/i', $sql, $m)) {
            // MySQL creates an index for every foreign key, SQLite does not: the index may not exist
            return ['DROP INDEX IF EXISTS `' . $m[2] . '.' . $m[1] . '`'];
        }
        if (preg_match('/^RENAME\s+TABLE\s+(.+?)\s+TO\s+(.+)$/is', $sql, $m)) {
            return ['ALTER TABLE ' . $m[1] . ' RENAME TO ' . $m[2]];
        }
        if (preg_match('/^CREATE\s+(UNIQUE\s+)?INDEX\s/i', $sql)) {
            return [(string) preg_replace('/(`?\w+`?)\(\d+\)/', '$1', $this->indexName($sql))];
        }

        return [$sql];
    }

    /**
     * SQLite index names are schema wide, MySQL ones are per table: `CREATE INDEX name ON table` becomes `table.name`
     */
    private function indexName(string $sql): string
    {
        return (string) preg_replace_callback('/^(CREATE\s+(?:UNIQUE\s+)?INDEX\s+(?:IF\s+NOT\s+EXISTS\s+)?)`?([\w.]+)`?\s+ON\s+`?(\w+)`?/i', static fn (array $m) => $m[1] . '`' . $m[3] . '.' . $m[2] . '` ON `' . $m[3] . '`', $sql);
    }

    /**
     * @return list<string>
     */
    private function createTable(string $sql): array
    {
        $sql = (string) preg_replace('/^\s*(?:#|-- ).*$/m', '', $sql);
        $sql = (string) preg_replace('/\bUNIQUE\s+(?:KEY|INDEX)\s*\(/i', 'UNIQUE (', $sql);
        if (!preg_match('/^(CREATE\s+(?:TEMPORARY\s+)?TABLE\s+(?:IF\s+NOT\s+EXISTS\s*)?`?(\w+)`?)\s*\((.*)\)\s*([^()]*)$/is', $sql, $m)) {
            self::$blockers[] = 'unparsed create table: ' . substr($sql, 0, 60);

            return [$sql];
        }
        $table = $m[2];
        $lines = [];
        $indexes = [];
        foreach (DdlRewriter::splitTopLevel($m[3]) as $line) {
            $line = trim($line);
            if (preg_match('/^(UNIQUE\s+)?(?:(?:KEY|INDEX)\s+)?`?([\w.]+)`?\s*(\(.*\))$/is', $line, $k) && !preg_match('/^(?:PRIMARY|CONSTRAINT|FOREIGN|CHECK)\b/i', $line)) {
                if (preg_match('/^(?:KEY|INDEX)$/i', $k[2])) {
                    $k[2] = 'idx.' . trim((string) preg_replace('/\W+/', '_', $k[3]), '_');
                }
                $k[3] = (string) preg_replace('/(`?\w+`?)\(\d+\)/', '$1', $k[3]);
                $indexes[] = 'CREATE ' . $k[1] . 'INDEX `' . $table . '.' . $k[2] . '` ON `' . $table . '` ' . $k[3];

                continue;
            }
            if (preg_match('/^FULLTEXT/i', $line)) {
                self::$blockers[] = 'FULLTEXT index in ' . $table;

                continue;
            }
            if (preg_match('/^`?(\w+)`?\s+.*\bAUTO_INCREMENT\b/i', $line, $a) && !preg_match('/PRIMARY\s+KEY/i', $line)) {
                // SQLite counts only an INTEGER PRIMARY KEY, a second counter column is filled by a trigger
                $line = (string) preg_replace('/\bNOT\s+NULL\b/i', 'NULL', $line);
                $indexes[] = 'CREATE TRIGGER `' . $table . '.auto_increment.' . $a[1] . '` AFTER INSERT ON `' . $table . '` WHEN NEW.`' . $a[1] . '` IS NULL BEGIN UPDATE `' . $table . '` SET `' . $a[1] . '` = (SELECT COALESCE(MAX(`' . $a[1] . '`), 0) + 1 FROM `' . $table . '`) WHERE rowid = NEW.rowid; END';
                self::$blockers[] = 'non primary key AUTO_INCREMENT emulated by trigger: ' . $table . '.' . $a[1];
            }
            $lines[] = $this->column($line);
        }

        return [$m[1] . " (\n" . implode(",\n", $lines) . "\n)", ...$indexes];
    }

    private function column(string $line): string
    {
        $line = (string) preg_replace('/\s+(?:UNSIGNED|ZEROFILL)\b/i', '', $line);
        // MySQL allows a name for the primary key: `PRIMARY KEY `id` (`id`)`
        $line = (string) preg_replace('/^PRIMARY\s+KEY\s+`?\w+`?\s*\(/i', 'PRIMARY KEY (', $line);
        $line = (string) preg_replace('/^FOREIGN\s+KEY\s+`([^`]+)`\s*\(/i', 'CONSTRAINT `$1` FOREIGN KEY (', $line);
        $line = (string) preg_replace('/\s+AUTO_INCREMENT\b/i', '', $line);
        $line = (string) preg_replace('/\bENUM\s*\((?:[^\')]|\'(?:[^\']|\'\')*\')*\)/i', 'VARCHAR(255)', $line);
        $line = (string) preg_replace('/\s+ON\s+UPDATE\s+CURRENT_TIMESTAMP(?:\(\d*\))?/i', '', $line);
        $line = (string) preg_replace('/\\s+COMMENT\\s+\'(?:[^\']|\'\')*\'/i', '', $line);
        $line = (string) preg_replace('/\s+(?:CHARACTER\s+SET|CHARSET)\s+\w+/i', '', $line);
        // generated columns: SQLite has no CONVERT(x, DATE)
        $line = (string) preg_replace('/\bCONVERT\(\s*(`?\w+`?)\s*,\s*DATE\s*\)/i', 'date($1)', $line);

        return $line;
    }

    /**
     * @return list<string|\Closure>
     */
    private function alterTable(string $table, string $actions): array
    {
        $out = [];
        $post = [];
        foreach (DdlRewriter::splitTopLevel($actions) as $action) {
            $action = trim($action);
            // SQLite can not add a UNIQUE column, the unique index is created next to it
            if (preg_match('/^(ADD\s+(?:COLUMN\s+)?`?(\w+)`?\s+.*?)\s+UNIQUE\b(?!\s*(?:KEY|INDEX|\())(.*)$/is', $action, $u) && !preg_match('/^ADD\s+(?:CONSTRAINT|UNIQUE)/i', $action)) {
                $action = $u[1] . $u[3];
                $post[] = 'CREATE UNIQUE INDEX `' . trim($table, '`') . '.uniq.' . $u[2] . '` ON ' . $table . ' (`' . $u[2] . '`)';
            }
            if (preg_match('/^ADD\s+(?:COLUMN\s+)?(`?\w+`?\s+.*)$/is', $action, $m) && !preg_match('/^(?:CONSTRAINT|FOREIGN|PRIMARY|UNIQUE|KEY|INDEX|FULLTEXT)\b/i', $action) && !preg_match('/^ADD\s+(?:CONSTRAINT|FOREIGN|PRIMARY|UNIQUE|KEY|INDEX|FULLTEXT)\b/i', $action)) {
                $autoIncrement = preg_match('/^`?(\w+)`?\s+.*\bAUTO_INCREMENT\b/is', $m[1], $a) === 1;
                $col = preg_replace('/\s+(?:AFTER\s+`?\w+`?|FIRST)\s*$/i', '', $this->column($autoIncrement ? (string) preg_replace('/\bNOT\s+NULL\b/i', 'NULL', $m[1]) : $m[1]));
                if ($autoIncrement) {
                    $t = trim($table, '`');
                    self::$blockers[] = 'non primary key AUTO_INCREMENT emulated by trigger: ' . $t . '.' . $a[1];
                    $post[] = 'UPDATE `' . $t . '` SET `' . $a[1] . '` = rowid WHERE `' . $a[1] . '` IS NULL';
                    $post[] = 'CREATE TRIGGER `' . $t . '.auto_increment.' . $a[1] . '` AFTER INSERT ON `' . $t . '` WHEN NEW.`' . $a[1] . '` IS NULL BEGIN UPDATE `' . $t . '` SET `' . $a[1] . '` = (SELECT COALESCE(MAX(`' . $a[1] . '`), 0) + 1 FROM `' . $t . '`) WHERE rowid = NEW.rowid; END';
                }
                // SQLite can not add a STORED generated column
                $col = preg_replace('/(GENERATED\s+ALWAYS\s+AS\s*\(.*\))\s+STORED\b/is', '$1 VIRTUAL', (string) $col);
                $out[] = 'ALTER TABLE ' . $table . ' ADD COLUMN ' . $col;
            } elseif (preg_match('/^ADD\s+(UNIQUE\s+)?(?:KEY|INDEX)\s+`?([\w.]+)`?\s*(\(.*\))$/is', $action, $m)) {
                $out[] = 'CREATE ' . $m[1] . 'INDEX `' . trim($table, '`') . '.' . $m[2] . '` ON ' . $table . ' ' . $m[3];
            } elseif (preg_match('/^DROP\s+(?:KEY|INDEX)\s+`?([\w.]+)`?$/i', $action, $m)) {
                $out[] = 'DROP INDEX IF EXISTS `' . trim($table, '`') . '.' . $m[1] . '`';
            } elseif (preg_match('/^DROP\s+(?:COLUMN\s+)?(`?\w+`?)$/i', $action, $m) && !preg_match('/^DROP\s+(?:FOREIGN|PRIMARY|CONSTRAINT)/i', $action)) {
                // SQLite refuses to drop a column a CHECK constraint mentions
                $column = trim($m[1], '`');
                $patch = $this->patchTable($table, static function (array $lines) use ($column): array {
                    $lines = array_values(array_filter($lines, static fn (string $l): bool => !preg_match('/^CONSTRAINT\s+`[^`]+`\s+CHECK\b.*`' . preg_quote($column, '/') . '`/is', $l)));

                    // MySQL removes the column from a composite unique key and keeps the key, SQLite would refuse the drop
                    return array_values(array_filter(array_map(static function (string $l) use ($column): ?string {
                        if (!preg_match('/^(CONSTRAINT\s+`[^`]+`\s+)?UNIQUE\s*(?:KEY\s+)?\((.*)\)$/is', $l, $u) || !preg_match('/`' . preg_quote($column, '/') . '`/', $u[2])) {
                            return $l;
                        }
                        $columns = array_values(array_filter(array_map('trim', explode(',', $u[2])), static fn (string $c): bool => trim($c, '`') !== $column));

                        return $columns === [] ? null : $u[1] . 'UNIQUE (' . implode(', ', $columns) . ')';
                    }, $lines), static fn (?string $l): bool => $l !== null));
                });
                if ($patch !== null) {
                    $out[] = $patch;
                }
                $column = trim($m[1], '`');
                // MySQL drops the column from its indexes, SQLite refuses: the indexes that contain it are dropped first
                $out[] = fn (): array => [
                    ...array_map(
                        static fn (string $index): string => 'DROP INDEX `' . $index . '`',
                        array_filter(explode("\x1f", (string) ($this->fetchOne)(
                            'SELECT group_concat(name, char(31)) FROM sqlite_master m WHERE type = \'index\' AND tbl_name = \'' . trim($table, '`') . '\' AND sql IS NOT NULL'
                            . ' AND EXISTS (SELECT 1 FROM pragma_index_info(m.name) WHERE name = \'' . $column . '\')'
                        )))
                    ),
                    ...array_map(
                        static fn (string $trigger): string => 'DROP TRIGGER `' . $trigger . '`',
                        array_filter(explode("\x1f", (string) ($this->fetchOne)(
                            'SELECT group_concat(name, char(31)) FROM sqlite_master WHERE type = \'trigger\' AND tbl_name = \'' . trim($table, '`') . '\' AND sql LIKE \'%NEW.%' . $column . '%\''
                        )))
                    ),
                    'ALTER TABLE ' . $table . ' DROP COLUMN ' . $m[1],
                ];
            } elseif (preg_match('/^RENAME\s+(?:TO\s+)?`?(\w+)`?$/i', $action, $m)) {
                $out[] = 'ALTER TABLE ' . $table . ' RENAME TO ' . $m[1];
            } elseif (preg_match('/^(?:MODIFY|CHANGE)\s+(?:COLUMN\s+)?`?(\w+)`?\s+(.*)$/is', $action, $m)) {
                $rename = null;
                $newName = '';
                $definition = $m[2];
                if (stripos($action, 'CHANGE') === 0 && preg_match('/^`?(\w+)`?\s+(.*)$/s', $m[2], $c)) {
                    $rename = $m[1];
                    $m[1] = $m[1];
                    $definition = $c[2];
                    $newName = (string) $c[1];
                }
                $definition = (string) preg_replace('/\s+(?:AFTER\s+`?\w+`?|FIRST)\s*$/i', '', $this->column($definition));
                $statements = $this->patchTable($table, static function (array $lines) use ($m, $definition, $rename, $newName): array {
                    foreach ($lines as $i => $line) {
                        if (preg_match('/^`?' . preg_quote($m[1], '/') . '`?\s/', ltrim($line))) {
                            $lines[$i] = '`' . ($rename !== null ? $newName : $m[1]) . '` ' . $definition;
                        }
                    }

                    return $lines;
                });
                if ($statements === null) {
                    self::$blockers[] = 'ALTER TABLE ' . $table . ' ' . substr($action, 0, 70);
                } else {
                    self::$blockers[] = 'MODIFY/CHANGE via writable_schema: ' . $table;
                    $out[] = $statements;
                }
            } elseif (preg_match('/^ADD\s+CONSTRAINT\s+`?([\w.]+)`?\s+UNIQUE\s*(?:KEY|INDEX)?\s*(\(.*\))$/is', $action, $m)) {
                $post[] = 'CREATE UNIQUE INDEX `' . trim($table, '`') . '.' . $m[1] . '` ON ' . $table . ' ' . $m[2];
            } elseif (preg_match('/^ADD\s+(CONSTRAINT\s+`[^`]+`\s+(?:FOREIGN\s+KEY|UNIQUE|CHECK).*|FOREIGN\s+KEY.*)$/is', $action, $m)) {
                $constraint = (string) preg_replace('/\bUNIQUE\s+(?:KEY|INDEX)\s*\(/i', 'UNIQUE (', $m[1]);
                // MySQL: `FOREIGN KEY `name`(col) REFERENCES ...`
                $constraint = (string) preg_replace('/^FOREIGN\s+KEY\s+`([^`]+)`\s*\(/i', 'CONSTRAINT `$1` FOREIGN KEY (', $constraint);
                $statements = $this->patchTable($table, static fn (array $lines): array => [...$lines, $constraint]);
                if ($statements === null) {
                    self::$blockers[] = 'ALTER TABLE ' . $table . ' ' . substr($action, 0, 70);
                } else {
                    self::$blockers[] = 'ADD CONSTRAINT via writable_schema: ' . $table;
                    $out[] = $statements;
                }
            } elseif (preg_match('/^DROP\s+(?:FOREIGN\s+KEY|CONSTRAINT|CHECK)\s+`?([\w.]+)`?$/i', $action, $m)) {
                $statements = $this->patchTable($table, static fn (array $lines): array => array_values(array_filter($lines, static fn (string $l): bool => stripos(ltrim($l), 'CONSTRAINT `' . $m[1] . '`') !== 0)));
                if ($statements === null) {
                    self::$blockers[] = 'ALTER TABLE ' . $table . ' ' . substr($action, 0, 70);
                } else {
                    self::$blockers[] = 'DROP CONSTRAINT via writable_schema: ' . $table;
                    $out[] = $statements;
                }
            } elseif (preg_match('/^DROP\s+PRIMARY\s+KEY$/i', $action)) {
                self::$blockers[] = 'DROP PRIMARY KEY via table rebuild: ' . $table;
                $out[] = fn (): array => $this->rebuildNow($table, static fn (array $lines): array => array_values(array_filter($lines, static fn (string $l): bool => stripos($l, 'PRIMARY KEY') !== 0)));
            } elseif (preg_match('/^ADD\s+PRIMARY\s+KEY\s*(\(.*\))$/is', $action, $m)) {
                self::$blockers[] = 'ADD PRIMARY KEY via table rebuild: ' . $table;
                $columns = $m[1];
                $out[] = fn (): array => $this->rebuildNow($table, static fn (array $lines): array => [...$lines, 'PRIMARY KEY ' . $columns]);
            } elseif (preg_match('/^(ENGINE|AUTO_INCREMENT|DEFAULT\s+CHARSET|CHARSET|COLLATE|ROW_FORMAT|COMMENT)\b/i', $action)) {
                continue;
            } else {
                self::$blockers[] = 'ALTER TABLE ' . $table . ' ' . substr($action, 0, 70);
            }
        }

        return [...$out, ...$post];
    }

    /**
     * SQLite has no ALTER COLUMN / ADD CONSTRAINT: the stored CREATE TABLE text is edited (writable_schema, documented procedure for changes that keep the data valid).
     *
     * @param \Closure(list<string>): list<string> $change receives the top level definitions inside the parentheses
     *
     * @return \Closure(): list<string> evaluated right before execution, an earlier action of the same ALTER TABLE may have changed the table
     */
    private function patchTable(string $table, \Closure $change): ?\Closure
    {
        if ($this->fetchOne === null) {
            return null;
        }

        return fn (): array => $this->patchNow($table, $change);
    }

    /**
     * Table rebuild (create new, copy, drop, rename) for changes the stored text cannot take, such as another primary key.
     * Needs `PRAGMA foreign_keys = OFF`, which SQLite ignores inside a transaction.
     *
     * @param \Closure(list<string>): list<string> $change
     *
     * @return list<string>
     */
    private function rebuildNow(string $table, \Closure $change): array
    {
        \assert($this->fetchOne !== null);
        $name = trim($table, '`');
        $sql = ($this->fetchOne)('SELECT sql FROM sqlite_master WHERE type = \'table\' AND name = \'' . $name . '\'');
        if (!\is_string($sql) || ($open = strpos($sql, '(')) === false || ($close = strrpos($sql, ')')) === false) {
            throw new \RuntimeException('Table definition of ' . $name . ' not readable');
        }
        $lines = array_map(static fn (string $l): string => trim($l), DdlRewriter::splitTopLevel(substr($sql, $open + 1, $close - $open - 1)));
        $new = 'CREATE TABLE `' . $name . '__new` (' . "\n" . implode(",\n", $change($lines)) . "\n" . substr($sql, $close);
        $dependents = (string) ($this->fetchOne)('SELECT group_concat(sql, char(31)) FROM sqlite_master WHERE type IN (\'index\', \'trigger\') AND tbl_name = \'' . $name . '\' AND sql IS NOT NULL');

        return [
            'PRAGMA foreign_keys = OFF',
            $new,
            'INSERT INTO `' . $name . '__new` SELECT * FROM `' . $name . '`',
            'DROP TABLE `' . $name . '`',
            'ALTER TABLE `' . $name . '__new` RENAME TO `' . $name . '`',
            ...($dependents === '' ? [] : explode("\x1f", $dependents)),
            'PRAGMA foreign_keys = ON',
        ];
    }

    /**
     * @param \Closure(list<string>): list<string> $change
     *
     * @return list<string>
     */
    private function patchNow(string $table, \Closure $change): array
    {
        \assert($this->fetchOne !== null);
        $name = trim($table, '`');
        $sql = ($this->fetchOne)('SELECT sql FROM sqlite_master WHERE type = \'table\' AND name = \'' . $name . '\'');
        if (!\is_string($sql) || ($open = strpos($sql, '(')) === false || ($close = strrpos($sql, ')')) === false) {
            throw new \RuntimeException('Table definition of ' . $name . ' not readable');
        }
        $lines = array_map(static fn (string $l): string => trim($l), DdlRewriter::splitTopLevel(substr($sql, $open + 1, $close - $open - 1)));
        $new = substr($sql, 0, $open + 1) . "\n" . implode(",\n", $change($lines)) . "\n" . substr($sql, $close);
        $version = (int) ($this->fetchOne)('PRAGMA schema_version');

        return [
            'PRAGMA writable_schema = ON',
            'UPDATE sqlite_master SET sql = \'' . str_replace('\'', '\'\'', $new) . '\' WHERE type = \'table\' AND name = \'' . $name . '\'',
            'PRAGMA schema_version = ' . ($version + 1),
            'PRAGMA writable_schema = RESET',
        ];
    }
}

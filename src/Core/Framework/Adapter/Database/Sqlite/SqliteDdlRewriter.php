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
        if (preg_match('/^SET\s+(?:SESSION\s+|GLOBAL\s+)?(?:default_storage_engine|foreign_key_checks|unique_checks|sql_mode|sql_notes|group_concat_max_len|innodb_\w+)\b/i', $sql)) {
            return ['SELECT 1'];
        }
        if (preg_match('/^CREATE\s+(?:TEMPORARY\s+)?TABLE/i', $sql)) {
            return $this->createTable($sql);
        }
        if (preg_match('/^ALTER\s+TABLE\s+(`?\w+`?)\s+(.*)$/is', $sql, $m)) {
            return $this->alterTable($m[1], $m[2]);
        }
        if (preg_match('/^DROP\s+INDEX\s+(`?[\w.]+`?)\s+ON\s+`?\w+`?$/i', $sql, $m)) {
            return ['DROP INDEX ' . $m[1]];
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
        $line = (string) preg_replace('/\s+AUTO_INCREMENT\b/i', '', $line);
        $line = (string) preg_replace('/\s+ON\s+UPDATE\s+CURRENT_TIMESTAMP(?:\(\d*\))?/i', '', $line);
        $line = (string) preg_replace("/\\s+COMMENT\\s+'(?:[^']|'')*'/i", '', $line);
        $line = (string) preg_replace('/\s+(?:CHARACTER\s+SET|CHARSET)\s+\w+/i', '', $line);

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
                $col = preg_replace('/\s+(?:AFTER\s+`?\w+`?|FIRST)\s*$/i', '', $this->column($m[1]));
                $out[] = 'ALTER TABLE ' . $table . ' ADD COLUMN ' . $col;
            } elseif (preg_match('/^ADD\s+(UNIQUE\s+)?(?:KEY|INDEX)\s+`?([\w.]+)`?\s*(\(.*\))$/is', $action, $m)) {
                $out[] = 'CREATE ' . $m[1] . 'INDEX `' . trim($table, '`') . '.' . $m[2] . '` ON ' . $table . ' ' . $m[3];
            } elseif (preg_match('/^DROP\s+(?:KEY|INDEX)\s+`?([\w.]+)`?$/i', $action, $m)) {
                $out[] = 'DROP INDEX IF EXISTS `' . trim($table, '`') . '.' . $m[1] . '`';
            } elseif (preg_match('/^DROP\s+(?:COLUMN\s+)?(`?\w+`?)$/i', $action, $m) && !preg_match('/^DROP\s+(?:FOREIGN|PRIMARY|CONSTRAINT)/i', $action)) {
                // SQLite refuses to drop a column a CHECK constraint mentions
                $column = trim($m[1], '`');
                $patch = $this->patchTable($table, static fn (array $lines): array => array_values(array_filter($lines, static fn (string $l): bool => !preg_match('/^CONSTRAINT\s+`[^`]+`\s+CHECK\b.*`' . preg_quote($column, '/') . '`/is', $l))));
                if ($patch !== null) {
                    $out[] = $patch;
                }
                $out[] = 'ALTER TABLE ' . $table . ' DROP COLUMN ' . $m[1];
            } elseif (preg_match('/^RENAME\s+(?:TO\s+)?`?(\w+)`?$/i', $action, $m)) {
                $out[] = 'ALTER TABLE ' . $table . ' RENAME TO ' . $m[1];
            } elseif (preg_match('/^(?:MODIFY|CHANGE)\s+(?:COLUMN\s+)?`?(\w+)`?\s+(.*)$/is', $action, $m)) {
                $rename = null;
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
     * @param \Closure(list<string>): list<string> $change
     *
     * @return list<string>
     */
    private function patchNow(string $table, \Closure $change): array
    {
        \assert($this->fetchOne !== null);
        $name = trim($table, '`');
        $sql = ($this->fetchOne)("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = '" . $name . "'");
        if (!\is_string($sql) || ($open = strpos($sql, '(')) === false || ($close = strrpos($sql, ')')) === false) {
            throw new \RuntimeException('Table definition of ' . $name . ' not readable');
        }
        $lines = array_map(static fn (string $l): string => trim($l), DdlRewriter::splitTopLevel(substr($sql, $open + 1, $close - $open - 1)));
        $new = substr($sql, 0, $open + 1) . "\n" . implode(",\n", $change($lines)) . "\n" . substr($sql, $close);
        $version = (int) ($this->fetchOne)('PRAGMA schema_version');

        return [
            'PRAGMA writable_schema = ON',
            "UPDATE sqlite_master SET sql = '" . str_replace("'", "''", $new) . "' WHERE type = 'table' AND name = '" . $name . "'",
            'PRAGMA schema_version = ' . ($version + 1),
            'PRAGMA writable_schema = OFF',
        ];
    }
}

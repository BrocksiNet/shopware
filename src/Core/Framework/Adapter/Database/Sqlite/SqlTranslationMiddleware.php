<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Adapter\Database\Sqlite;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Shopware\Core\Framework\Adapter\Database\PostgreSQL\DeleteRewriter;
use Shopware\Core\Framework\Adapter\Database\PostgreSQL\InsertSetRewriter;
use Shopware\Core\Framework\Adapter\Database\PostgreSQL\UpdateRewriter;
use Shopware\Core\Framework\Adapter\Database\PostgreSQL\UpsertRewriter;
use Shopware\Core\Framework\Log\Package;

/**
 * Rewrites MySQL DDL and the few DML spellings SQLite does not know (spike).
 *
 * @internal
 */
#[Package('framework')]
class SqlTranslationMiddleware implements Middleware
{
    public function wrap(Driver $driver): Driver
    {
        return new class($driver) extends AbstractDriverMiddleware {
            public function connect(#[\SensitiveParameter] array $params): Connection
            {
                return new class(parent::connect($params)) extends AbstractConnectionMiddleware {
                    private readonly SqliteDdlRewriter $ddl;

                    public function __construct(Connection $connection)
                    {
                        parent::__construct($connection);
                        $this->ddl = new SqliteDdlRewriter(fn (string $q): mixed => parent::query($q)->fetchOne());
                    }

                    public function prepare(string $sql): Statement
                    {
                        $statements = $this->translate($sql);
                        if (\is_string($all = getenv('SQLITE_SQL_LOG'))) {
                            file_put_contents($all, implode(';', array_filter($statements, 'is_string')) . "\n", \FILE_APPEND);
                        }
                        if (\count($statements) === 1 && \is_string($statements[0])) {
                            try {
                                return new TextBindingStatement(parent::prepare($statements[0]));
                            } catch (\Throwable $e) {
                                if (\is_string($log = getenv('SQLITE_FAIL_LOG'))) {
                                    file_put_contents($log, $e->getMessage() . ' | ' . $statements[0] . "\n", \FILE_APPEND);
                                }

                                throw $e;
                            }
                        }

                        return new MultiStatement($this, $statements);
                    }

                    public function query(string $sql): Result
                    {
                        $statements = $this->translate($sql);
                        $last = array_pop($statements);
                        foreach ($statements as $statement) {
                            $this->execRaw($statement);
                        }

                        return parent::query(\is_string($last) ? $last : 'SELECT 1');
                    }

                    public function exec(string $sql): int|string
                    {
                        $affected = 0;
                        foreach ($this->translate($sql) as $statement) {
                            $affected = $this->execRaw($statement);
                        }

                        return $affected;
                    }

                    public function execRaw(string|\Closure $sql): int|string
                    {
                        if ($sql instanceof \Closure) {
                            $affected = 0;
                            foreach ($sql() as $statement) {
                                $affected = parent::exec($statement);
                            }

                            return $affected;
                        }

                        try {
                            return parent::exec($sql);
                        } catch (\Throwable $e) {
                            if (\is_string($log = getenv('SQLITE_FAIL_LOG'))) {
                                file_put_contents($log, $e->getMessage() . ' | ' . $sql . "\n", \FILE_APPEND);
                            }

                            throw $e;
                        }
                    }

                    /**
                     * @return non-empty-list<string|\Closure>
                     */
                    private function translate(string $sql): array
                    {
                        SqliteDdlRewriter::$last = $sql;
                        if (!preg_match('/^\s*(?:CREATE|ALTER|DROP|RENAME|SET)\b/i', $sql)) {
                            return [$this->unescapeLiterals($this->dml($sql))];
                        }

                        return array_map(fn (string|\Closure $s) => \is_string($s) ? $this->unescapeLiterals((string) preg_replace('/(?<![\w`"\'])0x([0-9a-fA-F]{2,})(?![\w])/', 'X\'$1\'', $s)) : $s, $this->ddl->rewrite($sql) ?: ['SELECT 1']);
                    }

                    /**
                     * MySQL reads backslash escapes inside string literals (`'[\"a\"]'`, `'it\'s'`), SQLite does not.
                     * `\_` and `\%` stay: they are LIKE escapes.
                     */
                    private function unescapeLiterals(string $sql): string
                    {
                        if (!str_contains($sql, '\\')) {
                            return $sql;
                        }

                        return (string) preg_replace_callback('/\'((?:[^\'\\\\]|\\\\.|\'\')*)\'/s', static function (array $m): string {
                            $body = (string) preg_replace_callback('/\\\\(.)/s', static fn (array $e): string => match ($e[1]) {
                                '\'' => '\'\'',
                                'n' => "\n",
                                'r' => "\r",
                                't' => "\t",
                                '0' => "\0",
                                '_', '%' => $e[0],
                                default => $e[1],
                            }, $m[1]);

                            return '\'' . $body . '\'';
                        }, $sql);
                    }

                    /**
                     * @var array<string, list<string>>
                     */
                    private array $primaryKeys = [];

                    private function dml(string $sql): string
                    {
                        if (stripos($sql, 'information_schema.') !== false) {
                            $sql = (string) preg_replace(['/\binformation_schema\.columns\b/i', '/\binformation_schema\.tables\b/i'], ['sw_is_columns', 'sw_is_tables'], $sql);
                        }
                        if (stripos($sql, 'pragma_table_info(') !== false) {
                            // the schema manager lists columns with pragma_table_info, which hides generated columns, table_xinfo shows them
                            $sql = (string) preg_replace('/\bpragma_table_info\(/i', 'pragma_table_xinfo(', $sql);
                        }
                        if (str_contains($sql, '#')) {
                            // MySQL `# comment` lines, SQLite only knows `--` and block comments
                            $sql = (string) preg_replace_callback('/(\'(?:[^\'\\\\]|\\\\.|\'\')*\'|`[^`]*`|"[^"]*")|#[^\n]*/s', static fn (array $m): string => ($m[1] ?? '') !== '' ? $m[1] : '', $sql);
                        }
                        $sql = rtrim($sql, " \t\n\r;");
                        if (stripos($sql, 'TRIM(') !== false) {
                            $sql = (string) preg_replace_callback('/\bTRIM\s*(\((?:[^()\']|\'(?:[^\']|\'\')*\'|(?1))*\))/i', static function (array $m): string {
                                $inner = substr($m[1], 1, -1);
                                if (!preg_match('/^\s*(?:(LEADING|TRAILING|BOTH)\s+)?(\'(?:[^\']|\'\')*\')\s+FROM\s+(.*)$/is', $inner, $mm)) {
                                    return $m[0];
                                }
                                $fn = ['LEADING' => 'ltrim', 'TRAILING' => 'rtrim'][strtoupper($mm[1])] ?? 'trim';

                                return $fn . '(' . $mm[3] . ', ' . $mm[2] . ')';
                            }, $sql);
                        }
                        if (stripos($sql, 'SEPARATOR') !== false) {
                            $sql = (string) preg_replace_callback('/\bGROUP_CONCAT\s*(\((?:[^()\']|\'(?:[^\']|\'\')*\'|(?1))*\))/i', static function (array $m): string {
                                $inner = substr($m[1], 1, -1);
                                if (!preg_match('/^(.*)\sSEPARATOR\s+(\'(?:[^\']|\'\')*\')\s*$/is', $inner, $mm)) {
                                    return $m[0];
                                }
                                $expr = preg_replace('/^\s*DISTINCT\s+/i', '', $mm[1]);

                                return 'group_concat(' . $expr . ', ' . $mm[2] . ')';
                            }, $sql);
                        }
                        if (stripos($sql, 'JSON_TYPE') !== false) {
                            // JSON_EXTRACT returns the SQL value in SQLite, JSON in MySQL: JSON_TYPE(JSON_EXTRACT(a, p)) is json_type(a, p) with MySQL names
                            $sql = (string) preg_replace_callback('/\bJSON_TYPE\s*\(\s*JSON_EXTRACT\s*\(([^()\']*(?:\'[^\']*\'[^()\']*)*)\)\s*\)/i', static function (array $m): string {
                                $t = 'json_type(' . $m[1] . ')';

                                return '(CASE ' . $t . ' WHEN \'text\' THEN \'STRING\' WHEN \'true\' THEN \'BOOLEAN\' WHEN \'false\' THEN \'BOOLEAN\' WHEN \'real\' THEN \'DOUBLE\' ELSE UPPER(' . $t . ') END)';
                            }, $sql);
                        }
                        if (stripos($sql, 'COALESCE') !== false) {
                            $sql = $this->singleArgumentCoalesce($sql);
                        }
                        // null safe equals, row locks (SQLite locks the whole database)
                        $sql = str_replace('<=>', ' IS ', $sql);
                        $sql = (string) preg_replace('/\s+(?:FOR\s+(?:UPDATE|SHARE)(?:\s+(?:NOWAIT|SKIP\s+LOCKED))?|LOCK\s+IN\s+SHARE\s+MODE)\s*;?\s*$/i', '', $sql);
                        if (preg_match('/^\s*SELECT\s+@@(?:SESSION\.)?(\w+)\s*$/i', $sql, $v)) {
                            return 'SELECT ' . (stripos($v[1], 'foreign_key_checks') !== false ? '1' : '\'\'') . ' AS `@@' . $v[1] . '`';
                        }
                        if (preg_match('/INFORMATION_SCHEMA\.KEY_COLUMN_USAGE.*REFERENCED_TABLE_NAME\s*=\s*\'(\w+)\'/is', $sql, $v)) {
                            return 'SELECT m.name AS TABLE_NAME, f.`from` AS COLUMN_NAME, sw_fk_name(m.name, f.`from`) AS CONSTRAINT_NAME, f.`table` AS REFERENCED_TABLE_NAME, f.`to` AS REFERENCED_COLUMN_NAME'
                                . ' FROM sqlite_master m, pragma_foreign_key_list(m.name) f WHERE m.type = \'table\' AND f.`table` = \'' . $v[1] . '\' ORDER BY m.name, f.id, f.seq';
                        }
                        if (preg_match('/^\s*SHOW\s/i', $sql)) {
                            return SqliteShowRewriter::rewrite($sql);
                        }
                        if (preg_match('/^\s*TRUNCATE\s+(?:TABLE\s+)?`?(\w+)`?\s*;?\s*$/i', $sql, $m)) {
                            return 'DELETE FROM `' . $m[1] . '`';
                        }
                        if (preg_match('/^\s*(?:SAVEPOINT|RELEASE|ROLLBACK|BEGIN|COMMIT|SELECT|PRAGMA|WITH)\b/i', $sql) && !preg_match('/0x[0-9a-f]{2}/i', $sql)) {
                            return $sql;
                        }

                        $sql = (string) preg_replace('/^(\s*INSERT\s+(?:IGNORE\s+)?INTO\s+`?\w+`?(?:\s*\([^()]*\))?)\s*\(\s*(SELECT\b.*)\)\s*$/is', '$1 $2', $sql);

                        // 0x0fa9... is an integer literal in SQLite, MySQL treats it as binary
                        $sql = (string) preg_replace('/(?<![\w`"\'])0x([0-9a-fA-F]{2,})(?![\w])/', 'X\'$1\'', $sql);

                        if (!preg_match('/^\s*(?:UPDATE|DELETE|INSERT|REPLACE)\b/i', $sql)) {
                            return $sql;
                        }

                        if (preg_match('/^\s*INSERT\s+IGNORE\s+INTO/i', $sql) && !preg_match('/\bSET\s+\w+\s*=/i', $sql) && stripos($sql, 'DUPLICATE') === false) {
                            return (string) preg_replace('/^(\s*)INSERT\s+IGNORE\s+INTO/i', '$1INSERT OR IGNORE INTO', $sql);
                        }
                        $ignore = (bool) preg_match('/^\s*UPDATE\s+IGNORE\s+/i', $sql);
                        $sql = (string) preg_replace('/^(\s*UPDATE)\s+IGNORE\s+/i', '$1 ', $sql);
                        $sql = $this->updateAlias($sql);
                        $sql = $this->backticksToQuotes($sql);
                        if (stripos($sql, 'DEFAULT') !== false) {
                            $sql = $this->defaultInValues($sql);
                        }
                        if (stripos($sql, 'UPDATE') !== false) {
                            $sql = $this->stripSetQualifier($sql);
                            $sql = $this->updateLimit($sql);
                            $sql = (string) preg_replace('/\bctid\b/', 'rowid', UpdateRewriter::rewrite($sql));
                            // SQLite wants AS for the alias of the target table: `UPDATE t AS a SET ... FROM ...`
                            $sql = (string) preg_replace('/^(\s*UPDATE\s+"?\w+"?)\s+(?!AS\b|SET\b)("?\w+"?)\s+SET\s/i', '$1 AS $2 SET ', $sql);
                            if ($ignore) {
                                $sql = (string) preg_replace('/^(\s*UPDATE)\s+/i', '$1 OR IGNORE ', $sql);
                            }
                        }
                        if (stripos($sql, 'DELETE') !== false) {
                            $sql = $this->deleteJoin($sql);
                            $sql = DeleteRewriter::rewrite($sql);
                        }
                        if ((stripos($sql, 'INSERT') !== false || stripos($sql, 'REPLACE') !== false) && stripos($sql, ' SET') !== false) {
                            $sql = InsertSetRewriter::rewrite($sql);
                        }
                        if (preg_match('/^\s*(?:\/\*.*?\*\/\s*)?(?:INSERT\s+IGNORE|REPLACE)\b/is', $sql) || stripos($sql, 'DUPLICATE') !== false) {
                            $sql = (string) preg_replace('/^(\s*)REPLACE\s+INTO/i', '$1REPLACE INTO', $sql);
                            $sql = UpsertRewriter::rewrite($sql, $this->getPrimaryKey(...));
                            $sql = (string) preg_replace('/^(\s*)REPLACE\s+INTO/i', '$1INSERT OR REPLACE INTO', $sql);
                        }

                        return $sql;
                    }

                    /**
                     * SQLite has no `DEFAULT` in a `VALUES` row (the multi row insert of the DAL uses it for columns a row does not set),
                     * it becomes the declared default of the column, or NULL.
                     */
                    private function defaultInValues(string $sql): string
                    {
                        if (!preg_match('/^(\s*INSERT\s+(?:OR\s+\w+\s+)?INTO\s+"?(\w+)"?\s*\(([^)]*)\)\s*VALUES\s*)(.*)$/is', $sql, $m)) {
                            return $sql;
                        }
                        $columns = array_map(static fn (string $c): string => trim($c, " \t\n\"`"), explode(',', $m[3]));
                        $rest = $m[4];
                        $out = '';
                        $depth = 0;
                        $index = 0;
                        $defaults = null;
                        for ($i = 0, $n = \strlen($rest); $i < $n; ++$i) {
                            $c = $rest[$i];
                            if ($c === '\'') {
                                $j = $i + 1;
                                while ($j < $n && !($rest[$j] === '\'' && ($rest[$j + 1] ?? '') !== '\'')) {
                                    $j += $rest[$j] === '\'' ? 2 : 1;
                                }
                                $out .= substr($rest, $i, $j - $i + 1);
                                $i = $j;

                                continue;
                            }
                            if ($c === '(') {
                                ++$depth;
                                $index = $depth === 1 ? 0 : $index;
                            } elseif ($c === ')') {
                                --$depth;
                            } elseif ($c === ',' && $depth === 1) {
                                ++$index;
                            } elseif ($depth === 1 && preg_match('/\GDEFAULT\b/i', $rest, $d, 0, $i) && !preg_match('/[\w"]/', $rest[$i - 1] ?? ' ')) {
                                $defaults ??= $this->columnDefaults($m[2]);
                                $out .= $defaults[$columns[$index] ?? ''] ?? 'NULL';
                                $i += 6;

                                continue;
                            }
                            $out .= $c;
                        }

                        return $m[1] . $out;
                    }

                    /**
                     * @return array<string, string>
                     */
                    private function columnDefaults(string $table): array
                    {
                        $defaults = [];
                        foreach (parent::query('SELECT name, dflt_value FROM pragma_table_xinfo(' . parent::quote($table) . ')')->fetchAllNumeric() as [$name, $default]) {
                            $defaults[(string) $name] = $default === null ? 'NULL' : '(' . $default . ')';
                        }

                        return $defaults;
                    }

                    /**
                     * `UPDATE table alias SET alias.col = ...`
                     */
                    private function updateAlias(string $sql): string
                    {
                        if (!preg_match('/^(\s*UPDATE\s+)(`?\w+`?)\s+(?:AS\s+)?(?!SET\b|INNER\b|LEFT\b|JOIN\b|CROSS\b)(`?\w+`?)\s+SET\s/i', $sql, $m)) {
                            return $sql;
                        }
                        $alias = trim($m[3], '`');
                        $rest = substr($sql, \strlen($m[0]));
                        $rest = (string) preg_replace('/(?<![\w`])`?' . preg_quote($alias, '/') . '`?\./', trim($m[2], '`') . '.', $rest);

                        return $m[1] . $m[2] . ' SET ' . $rest;
                    }

                    /**
                     * `DELETE a FROM t AS a JOIN ... WHERE ...` becomes `DELETE FROM t WHERE rowid IN (SELECT a.rowid FROM t AS a JOIN ... WHERE ...)`
                     */
                    private function deleteJoin(string $sql): string
                    {
                        $sql = rtrim(trim($sql), ';');
                        if (!preg_match('/^(\s*DELETE\s+)("?\w+"?)\s+FROM\s+("?\w+"?)(?:\s+AS)?\s+("?\w+"?)?\s*((?:INNER\s+|LEFT\s+)?JOIN\b.*)$/is', $sql, $m)) {
                            return $sql;
                        }
                        $alias = $m[4] !== '' ? $m[4] : $m[3];

                        return 'DELETE FROM ' . $m[3] . ' WHERE rowid IN (SELECT ' . $m[2] . '.rowid FROM ' . $m[3] . ' AS ' . $alias . ' ' . $m[5] . ')';
                    }

                    /**
                     * SQLite rejects a qualified assignment target (`SET product.col = 1`)
                     */
                    private function stripSetQualifier(string $sql): string
                    {
                        if (!preg_match('/^\s*UPDATE\s+("?\w+"?)\s+SET\s/i', $sql, $m) || stripos($sql, ' JOIN ') !== false) {
                            return $sql;
                        }

                        return (string) preg_replace('/((?:\bSET|,)\s+)' . preg_quote($m[1], '/') . '\.("?\w+"?\s*=)/i', '$1$2', $sql);
                    }

                    /**
                     * MySQL accepts `COALESCE(x)`, SQLite wants two arguments.
                     */
                    private function singleArgumentCoalesce(string $sql): string
                    {
                        for ($pass = 0; $pass < 3; ++$pass) {
                            $changed = false;
                            $sql = (string) preg_replace_callback('/\bCOALESCE\s*(\((?:[^()\']|\'(?:[^\']|\'\')*\'|(?1))*\))/i', static function (array $m) use (&$changed): string {
                                $inner = substr($m[1], 1, -1);
                                $depth = 0;
                                $quoted = false;
                                for ($i = 0, $n = \strlen($inner); $i < $n; ++$i) {
                                    $c = $inner[$i];
                                    if ($c === '\'') {
                                        $quoted = !$quoted;
                                    } elseif (!$quoted && $c === '(') {
                                        ++$depth;
                                    } elseif (!$quoted && $c === ')') {
                                        --$depth;
                                    } elseif (!$quoted && $depth === 0 && $c === ',') {
                                        return $m[0];
                                    }
                                }
                                $changed = true;

                                return '(' . $inner . ')';
                            }, $sql);
                            if (!$changed) {
                                break;
                            }
                        }

                        return $sql;
                    }

                    private function updateLimit(string $sql): string
                    {
                        if (!preg_match('/^(\s*UPDATE\s+)([`"]?\w+[`"]?)(\s+SET\s+.*?)\s+LIMIT\s+(\d+)\s*;?$/is', $sql, $m)) {
                            return $sql;
                        }

                        // the WHERE of the UPDATE, not the one of a subquery in SET
                        $depth = 0;
                        $where = null;
                        $set = $m[3];
                        for ($i = 0, $n = \strlen($set); $i < $n; ++$i) {
                            $ch = $set[$i];
                            if ($ch === '\'') {
                                for (++$i; $i < $n && !($set[$i] === '\'' && ($set[$i + 1] ?? '') !== '\''); ++$i) {
                                    $i += $set[$i] === '\'' ? 1 : 0;
                                }
                            } elseif ($ch === '(') {
                                ++$depth;
                            } elseif ($ch === ')') {
                                --$depth;
                            } elseif ($depth === 0 && preg_match('/\GWHERE\s/i', $set, $w, 0, $i) && preg_match('/\s/', $set[$i - 1] ?? ' ')) {
                                $where = $i;

                                break;
                            }
                        }
                        $condition = $where === null ? '' : ' WHERE ' . substr($set, $where + 6);
                        $set = $where === null ? $set : substr($set, 0, $where);

                        return $m[1] . $m[2] . $set . ' WHERE rowid IN (SELECT rowid FROM ' . $m[2] . $condition . ' LIMIT ' . $m[4] . ')';
                    }

                    private function backticksToQuotes(string $sql): string
                    {
                        return (string) preg_replace_callback('/\'(?:[^\'\\\\]|\\\\.|\'\')*\'|`([^`]+)`/s', static fn (array $m) => isset($m[1]) ? '"' . $m[1] . '"' : $m[0], $sql);
                    }

                    /**
                     * @return list<string>
                     */
                    private function getPrimaryKey(string $table): array
                    {
                        if (isset($this->primaryKeys[$table])) {
                            return $this->primaryKeys[$table];
                        }
                        $rows = parent::query('SELECT name FROM pragma_table_info(' . parent::quote($table) . ') WHERE pk > 0 ORDER BY pk')->fetchFirstColumn();

                        return $this->primaryKeys[$table] = array_map(strval(...), $rows);
                    }
                };
            }
        };
    }
}

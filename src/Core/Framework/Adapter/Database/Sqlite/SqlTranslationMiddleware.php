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
                        if (\count($statements) === 1 && \is_string($statements[0])) {
                            return parent::prepare($statements[0]);
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

                        return parent::exec($sql);
                    }

                    /**
                     * @return non-empty-list<string|\Closure>
                     */
                    private function translate(string $sql): array
                    {
                        SqliteDdlRewriter::$last = $sql;
                        if (!preg_match('/^\s*(?:CREATE|ALTER|DROP|RENAME|SET)\b/i', $sql)) {
                            return [$this->dml($sql)];
                        }

                        return $this->ddl->rewrite($sql) ?: ['SELECT 1'];
                    }

                    /**
                     * @var array<string, list<string>>
                     */
                    private array $primaryKeys = [];

                    private function dml(string $sql): string
                    {
                        if (preg_match('/^\s*SELECT\s+@@(?:SESSION\.)?(\w+)\s*$/i', $sql, $v)) {
                            return 'SELECT ' . (stripos($v[1], 'foreign_key_checks') !== false ? '1' : "''") . ' AS `@@' . $v[1] . '`';
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

                        $sql = (string) preg_replace('/^(\s*INSERT\s+INTO\s+`?\w+`?)\s*\(\s*(SELECT\b.*)\)\s*$/is', '$1 $2', $sql);

                        // 0x0fa9... is an integer literal in SQLite, MySQL treats it as binary
                        $sql = (string) preg_replace('/(?<![\w`"\'])0x([0-9a-fA-F]{2,})(?![\w])/', "X'$1'", $sql);

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
                        if (stripos($sql, 'UPDATE') !== false) {
                            $sql = $this->stripSetQualifier($sql);
                            $sql = $this->updateLimit($sql);
                            $sql = (string) preg_replace('/\bctid\b/', 'rowid', UpdateRewriter::rewrite($sql));
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

                    private function updateLimit(string $sql): string
                    {
                        if (!preg_match('/^(\s*UPDATE\s+)([`"]?\w+[`"]?)(\s+SET\s+.*?)(?:\s+WHERE\s+(.*?))?\s+LIMIT\s+(\d+)\s*;?$/is', $sql, $m)) {
                            return $sql;
                        }

                        return $m[1] . $m[2] . $m[3] . ' WHERE rowid IN (SELECT rowid FROM ' . $m[2] . ($m[4] !== '' ? ' WHERE ' . $m[4] : '') . ' LIMIT ' . $m[5] . ')';
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

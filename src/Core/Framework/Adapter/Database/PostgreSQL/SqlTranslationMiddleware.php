<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Adapter\Database\PostgreSQL;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Shopware\Core\Framework\Log\Package;

/**
 * Sends every statement through the MySQL to PostgreSQL translator before it reaches the driver.
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
                return new class(parent::connect($params), new MySQLToPostgreSQLTranslator()) extends AbstractConnectionMiddleware {
                    /**
                     * @var array<string, list<string>>
                     */
                    private array $primaryKeys = [];

                    /**
                     * @var array<string, list<string>>
                     */
                    private array $booleanColumns = [];

                    private bool $inTransaction = false;

                    public function __construct(Connection $connection, private readonly MySQLToPostgreSQLTranslator $translator)
                    {
                        parent::__construct($connection);
                    }

                    public function prepare(string $sql): Statement
                    {
                        [$translated, $caseMap] = $this->translator->translate($sql);
                        $statement = new ColumnCaseStatement(parent::prepare($this->rewriteStatement($translated)), $caseMap);

                        if (!$this->inTransaction || !DdlRewriter::isDdl($translated)) {
                            return $statement;
                        }

                        return new DdlSavepointStatement($statement, $this->inSavepoint(...));
                    }

                    public function query(string $sql): Result
                    {
                        [$translated, $caseMap] = $this->translator->translate($sql);
                        $result = parent::query(ShowRewriter::matches($translated) ? ShowRewriter::rewrite($translated) : (InformationSchemaRewriter::matches($translated) ? InformationSchemaRewriter::rewrite($translated) : $translated));

                        return $caseMap === [] ? $result : new ColumnCaseResult($result, $caseMap);
                    }

                    public function exec(string $sql): int|string
                    {
                        $translated = $this->translator->translate($sql)[0];
                        $rewritten = $this->rewriteStatement($translated);

                        if (!$this->inTransaction || !DdlRewriter::isDdl($translated)) {
                            return parent::exec($rewritten);
                        }

                        return $this->inSavepoint(fn (): int|string => parent::exec($rewritten));
                    }

                    /**
                     * A failed statement aborts the whole PostgreSQL transaction, MySQL migrations catch and ignore failing DDL (e.g. "PK already exists")
                     *
                     * @template T
                     *
                     * @param \Closure(): T $run
                     *
                     * @return T
                     */
                    private function inSavepoint(\Closure $run): mixed
                    {
                        parent::exec('SAVEPOINT sw_ddl');
                        try {
                            $result = $run();
                        } catch (\Throwable $e) {
                            parent::exec('ROLLBACK TO SAVEPOINT sw_ddl');
                            parent::exec('RELEASE SAVEPOINT sw_ddl');

                            throw $e;
                        }
                        parent::exec('RELEASE SAVEPOINT sw_ddl');

                        return $result;
                    }

                    public function beginTransaction(): void
                    {
                        parent::beginTransaction();
                        $this->inTransaction = true;
                    }

                    public function commit(): void
                    {
                        parent::commit();
                        $this->inTransaction = false;
                    }

                    public function rollBack(): void
                    {
                        parent::rollBack();
                        $this->inTransaction = false;
                    }

                    private function rewriteStatement(string $sql): string
                    {
                        if (DdlRewriter::isDdl($sql)) {
                            // migrations add and change columns, the cached column lists are stale afterwards
                            $this->booleanColumns = [];
                            $this->primaryKeys = [];
                        }

                        $sql = $this->rewriteMySqlStatement($sql);
                        if (stripos($sql, 'INSERT') === false && stripos($sql, 'UPDATE') === false) {
                            return $sql;
                        }

                        return BooleanAssignmentRewriter::rewrite($sql, $this->getBooleanColumns(...));
                    }

                    private function rewriteMySqlStatement(string $sql): string
                    {
                        if (InformationSchemaRewriter::matches($sql)) {
                            $sql = InformationSchemaRewriter::rewrite($sql);
                        }

                        if (ShowRewriter::matches($sql)) {
                            return ShowRewriter::rewrite($sql);
                        }

                        if (DdlRewriter::isDdl($sql)) {
                            return (new DdlRewriter($this->getColumnType(...)))->rewrite($sql);
                        }

                        if (stripos($sql, 'UPDATE') !== false) {
                            $sql = UpdateRewriter::rewrite($sql);
                        }

                        if (stripos($sql, 'DELETE') !== false) {
                            $sql = DeleteRewriter::rewrite($sql);
                        }

                        if (stripos($sql, 'SELECT') !== false && (stripos($sql, 'INSERT') !== false || stripos($sql, 'REPLACE') !== false)) {
                            $sql = InsertSelectRewriter::rewrite($sql, $this->getColumnType(...));
                        }

                        if ((stripos($sql, 'INSERT') !== false || stripos($sql, 'REPLACE') !== false) && stripos($sql, ' SET') !== false) {
                            $sql = InsertSetRewriter::rewrite($sql);
                        }

                        if (stripos($sql, 'IGNORE') === false && stripos($sql, 'REPLACE') === false && stripos($sql, 'DUPLICATE') === false) {
                            return $sql;
                        }

                        return UpsertRewriter::rewrite($sql, $this->getPrimaryKey(...));
                    }

                    private function getColumnType(string $table, string $column): ?string
                    {
                        $type = parent::query(
                            'SELECT format_type(a.atttypid, a.atttypmod) FROM pg_attribute a'
                            . ' WHERE a.attrelid = to_regclass(' . parent::quote('"' . $table . '"') . ') AND a.attname = ' . parent::quote($column) . ' AND NOT a.attisdropped'
                        )->fetchOne();

                        return $type === false ? null : (string) $type;
                    }

                    /**
                     * @return list<string>
                     */
                    private function getBooleanColumns(string $table): array
                    {
                        if (isset($this->booleanColumns[$table])) {
                            return $this->booleanColumns[$table];
                        }

                        $columns = parent::query(
                            'SELECT a.attname FROM pg_attribute a'
                            . ' WHERE a.attrelid = to_regclass(' . parent::quote('"' . str_replace('"', '""', $table) . '"') . ')'
                            . ' AND a.atttypid = \'boolean\'::regtype AND a.attnum > 0 AND NOT a.attisdropped'
                        )->fetchFirstColumn();

                        return $this->booleanColumns[$table] = array_map(strval(...), $columns);
                    }

                    /**
                     * @return list<string>
                     */
                    private function getPrimaryKey(string $table): array
                    {
                        if (isset($this->primaryKeys[$table])) {
                            return $this->primaryKeys[$table];
                        }

                        $columns = parent::query(
                            'SELECT a.attname FROM pg_index i'
                            . ' JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey)'
                            . ' WHERE i.indisprimary AND i.indrelid = to_regclass(' . parent::quote('"' . $table . '"') . ')'
                            . ' ORDER BY array_position(i.indkey::int[], a.attnum::int)'
                        )->fetchFirstColumn();

                        return $this->primaryKeys[$table] = array_map(strval(...), $columns);
                    }
                };
            }
        };
    }
}

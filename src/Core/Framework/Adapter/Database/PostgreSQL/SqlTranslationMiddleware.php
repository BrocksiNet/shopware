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

                    public function __construct(Connection $connection, private readonly MySQLToPostgreSQLTranslator $translator)
                    {
                        parent::__construct($connection);
                    }

                    public function prepare(string $sql): Statement
                    {
                        [$translated, $caseMap] = $this->translator->translate($sql);

                        return new ColumnCaseStatement(parent::prepare($this->rewriteStatement($translated)), $caseMap);
                    }

                    public function query(string $sql): Result
                    {
                        [$translated, $caseMap] = $this->translator->translate($sql);
                        $result = parent::query($translated);

                        return $caseMap === [] ? $result : new ColumnCaseResult($result, $caseMap);
                    }

                    public function exec(string $sql): int|string
                    {
                        return parent::exec($this->rewriteStatement($this->translator->translate($sql)[0]));
                    }

                    private function rewriteStatement(string $sql): string
                    {
                        if (stripos($sql, 'UPDATE') !== false) {
                            $sql = UpdateRewriter::rewrite($sql);
                        }

                        if (stripos($sql, 'IGNORE') === false && stripos($sql, 'REPLACE') === false && stripos($sql, 'DUPLICATE') === false) {
                            return $sql;
                        }

                        return UpsertRewriter::rewrite($sql, $this->getPrimaryKey(...));
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

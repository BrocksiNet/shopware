<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Adapter\Database\PostgreSQL;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractResultMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\ParameterType;
use Shopware\Core\Framework\Log\Package;

/**
 * Benchmark probe, only inserted by PostgreSQLFactory when SHOPWARE_PG_TIMING is set to a file path.
 * Sums the time spent in every connection, statement and result call below this probe.
 * Two probes around a layer give the cost of that layer (difference of their totals).
 * At the end of the request one JSON line with the totals per probe is appended to the file.
 *
 * @internal
 */
#[Package('framework')]
class TimingMiddleware implements Middleware
{
    /**
     * @var array<string, array{ns: int, calls: int, stmts: int}>
     */
    public static array $totals = [];

    private static bool $registered = false;

    public function __construct(private readonly string $label, private readonly string $file)
    {
        self::$totals[$label] = ['ns' => 0, 'calls' => 0, 'stmts' => 0];

        if (!self::$registered) {
            self::$registered = true;
            register_shutdown_function(static function () use ($file): void {
                $line = json_encode([
                    'uri' => $_SERVER['REQUEST_URI'] ?? implode(' ', $_SERVER['argv'] ?? []),
                    'wall_ns' => (int) ((microtime(true) - (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true))) * 1e9),
                    'probes' => self::$totals,
                ]);
                file_put_contents($file, $line . "\n", \FILE_APPEND | \LOCK_EX);
            });
        }
    }

    public function wrap(Driver $driver): Driver
    {
        $label = $this->label;

        return new class($driver, $label) extends AbstractDriverMiddleware {
            public function __construct(Driver $driver, private readonly string $label)
            {
                parent::__construct($driver);
            }

            public function connect(#[\SensitiveParameter] array $params): Connection
            {
                return new class(parent::connect($params), $this->label) extends AbstractConnectionMiddleware {
                    public function __construct(Connection $connection, private readonly string $label)
                    {
                        parent::__construct($connection);
                    }

                    public function prepare(string $sql): Statement
                    {
                        $t = hrtime(true);
                        $statement = parent::prepare($sql);
                        TimingMiddleware::add($this->label, $t);
                        ++TimingMiddleware::$totals[$this->label]['stmts'];

                        return $this->wrapStatement($statement);
                    }

                    public function query(string $sql): Result
                    {
                        $t = hrtime(true);
                        $result = parent::query($sql);
                        TimingMiddleware::add($this->label, $t);
                        ++TimingMiddleware::$totals[$this->label]['stmts'];

                        return $this->wrapResult($result);
                    }

                    public function exec(string $sql): int|string
                    {
                        $t = hrtime(true);
                        $r = parent::exec($sql);
                        TimingMiddleware::add($this->label, $t);
                        ++TimingMiddleware::$totals[$this->label]['stmts'];

                        return $r;
                    }

                    private function wrapStatement(Statement $statement): Statement
                    {
                        $label = $this->label;

                        return new class($statement, $label) extends AbstractStatementMiddleware {
                            public function __construct(Statement $statement, private readonly string $label)
                            {
                                parent::__construct($statement);
                            }

                            public function bindValue(int|string $param, mixed $value, ParameterType $type): void
                            {
                                $t = hrtime(true);
                                parent::bindValue($param, $value, $type);
                                TimingMiddleware::add($this->label, $t);
                            }

                            public function execute(): Result
                            {
                                $t = hrtime(true);
                                $result = parent::execute();
                                TimingMiddleware::add($this->label, $t);

                                return TimingMiddleware::wrapResult($result, $this->label);
                            }
                        };
                    }

                    private function wrapResult(Result $result): Result
                    {
                        return TimingMiddleware::wrapResult($result, $this->label);
                    }
                };
            }
        };
    }

    public static function add(string $label, int $start): void
    {
        self::$totals[$label]['ns'] += hrtime(true) - $start;
        ++self::$totals[$label]['calls'];
    }

    public static function wrapResult(Result $result, string $label): Result
    {
        return new class($result, $label) extends AbstractResultMiddleware {
            public function __construct(Result $result, private readonly string $label)
            {
                parent::__construct($result);
            }

            public function fetchNumeric(): array|false
            {
                $t = hrtime(true);
                $r = parent::fetchNumeric();
                TimingMiddleware::add($this->label, $t);

                return $r;
            }

            public function fetchAssociative(): array|false
            {
                $t = hrtime(true);
                $r = parent::fetchAssociative();
                TimingMiddleware::add($this->label, $t);

                return $r;
            }

            public function fetchOne(): mixed
            {
                $t = hrtime(true);
                $r = parent::fetchOne();
                TimingMiddleware::add($this->label, $t);

                return $r;
            }

            public function fetchAllNumeric(): array
            {
                $t = hrtime(true);
                $r = parent::fetchAllNumeric();
                TimingMiddleware::add($this->label, $t);

                return $r;
            }

            public function fetchAllAssociative(): array
            {
                $t = hrtime(true);
                $r = parent::fetchAllAssociative();
                TimingMiddleware::add($this->label, $t);

                return $r;
            }

            public function fetchFirstColumn(): array
            {
                $t = hrtime(true);
                $r = parent::fetchFirstColumn();
                TimingMiddleware::add($this->label, $t);

                return $r;
            }
        };
    }
}

<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\Harness;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Telemetry\Doctrine\QueryCountMiddleware;

/**
 * Counts the SQL statements that run through the Shopware DBAL connection (DAL, DBAL, Doctrine ORM, DAL v2 on that connection).
 * Uses the `QueryCountMiddleware` that `KernelFactory` registers on the connection (same lookup as `HttpRequestMetricSubscriber`).
 * It counts `exec`, `query` and executed prepared statements, not `BEGIN`/`COMMIT`.
 * An implementation with its own connection (Cycle: `cycle/database`) is counted by that implementation: see
 * {@see self::add()}, which it calls for every statement.
 *
 * @internal
 */
#[Package('framework')]
final class StatementCounter
{
    private ?QueryCountMiddleware $middleware = null;

    private int $external = 0;

    public function __construct(Connection $connection)
    {
        foreach ($connection->getConfiguration()->getMiddlewares() as $middleware) {
            if ($middleware instanceof QueryCountMiddleware) {
                $this->middleware = $middleware;
            }
        }
    }

    /**
     * For implementations that do not use the Shopware connection: call once per executed statement.
     */
    public function add(int $statements = 1): void
    {
        $this->external += $statements;
    }

    /**
     * Returns the statements since the last reset and starts again from zero.
     */
    public function reset(): int
    {
        $count = $this->external;
        $this->external = 0;

        if ($this->middleware === null) {
            return $count;
        }

        return $count + $this->middleware->getCounter()->reset();
    }

    public function isAvailable(): bool
    {
        return $this->middleware !== null;
    }
}

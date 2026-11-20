<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Adapter\Database\PostgreSQL;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Shopware\Core\Framework\Log\Package;

/**
 * Reuses PostgreSQL query plans across requests on a persistent connection (docs/22-postgresql-connection-and-planning.md).
 *
 * pdo_pgsql runs every DBAL statement unnamed (DBAL sets `ATTR_DISABLE_PREPARES`), so PostgreSQL parses and plans the same wide DAL statement again for every request.
 * This middleware turns a read statement into a server side prepared statement once per connection (`PREPARE sw_<hash> AS ...`) and runs it as `EXECUTE sw_<hash>(...)` afterwards.
 * PostgreSQL then switches to a generic plan after five executions (`plan_cache_mode = auto`), so planning disappears from the request.
 *
 * It only makes sense on a persistent connection (`DATABASE_PERSISTENT_CONNECTION`), the prepared statements live as long as the backend.
 * It is not usable behind PgBouncer in transaction mode, SQL level `PREPARE` is not tracked there.
 * Only `SELECT` and `WITH` statements without multiple statements and without dollar quoting are cached, everything else runs unchanged.
 *
 * @internal
 */
#[Package('framework')]
class PlanCacheMiddleware implements Middleware
{
    public function __construct(private readonly int $limit = 300)
    {
    }

    public function wrap(Driver $driver): Driver
    {
        $limit = $this->limit;

        return new class($driver, $limit) extends AbstractDriverMiddleware {
            public function __construct(Driver $driver, private readonly int $limit)
            {
                parent::__construct($driver);
            }

            public function connect(#[\SensitiveParameter] array $params): Connection
            {
                return new PlanCacheConnection(parent::connect($params), $this->limit);
            }
        };
    }

    /**
     * Rewrites `?` and `:name` placeholders to `$n` and returns the placeholders for the EXECUTE call, or null if the statement is not cacheable.
     *
     * @return array{sql: string, arguments: list<string>}|null
     */
    public static function rewrite(string $sql): ?array
    {
        // the DAL puts a `-- entity::read` comment in front of its statements
        if (!preg_match('/^(?:\s|--[^\n]*\n|\/\*.*?\*\/)*(?:\(\s*)*(?:select|with)\b/is', $sql)) {
            return null;
        }

        $length = \strlen($sql);
        $out = '';
        $positional = 0;
        $named = [];
        $i = 0;

        while ($i < $length) {
            $c = $sql[$i];
            $next = $sql[$i + 1] ?? '';

            if ($c === '\'' || $c === '"') {
                $end = $i + 1;
                while (true) {
                    $end = strpos($sql, $c, $end);
                    if ($end === false) {
                        return null;
                    }
                    if (($sql[$end + 1] ?? '') === $c) {
                        $end += 2;

                        continue;
                    }

                    break;
                }
                $quoted = substr($sql, $i, $end - $i + 1);
                // E'..' strings have backslash escapes, leave such statements alone (a backslash in a normal string is a plain character)
                if ($c === '\'' && $i > 0 && ($sql[$i - 1] === 'E' || $sql[$i - 1] === 'e') && ($i < 2 || preg_match('/[A-Za-z0-9_$]/', $sql[$i - 2]) !== 1)) {
                    return null;
                }
                $out .= $quoted;
                $i = $end + 1;

                continue;
            }

            if ($c === '-' && $next === '-') {
                $end = strpos($sql, "\n", $i);
                $end = $end === false ? $length : $end;
                $out .= substr($sql, $i, $end - $i);
                $i = $end;

                continue;
            }

            if ($c === '/' && $next === '*') {
                $end = strpos($sql, '*/', $i + 2);
                if ($end === false) {
                    return null;
                }
                $out .= substr($sql, $i, $end + 2 - $i);
                $i = $end + 2;

                continue;
            }

            if ($c === '$' || ($c === ';' && trim(substr($sql, $i + 1)) !== '')) {
                return null;
            }

            if ($c === ';') {
                break;
            }

            if ($c === '?') {
                if ($next === '?') {
                    $out .= '?';
                    $i += 2;

                    continue;
                }
                if ($named !== []) {
                    return null;
                }
                $out .= '$' . (++$positional);
                ++$i;

                continue;
            }

            if ($c === ':') {
                if ($next === ':') {
                    $out .= '::';
                    $i += 2;

                    continue;
                }
                if (preg_match('/\G:([A-Za-z_][A-Za-z0-9_]*)/', $sql, $match, 0, $i) === 1) {
                    if ($positional > 0) {
                        return null;
                    }
                    $name = $match[1];
                    if (!isset($named[$name])) {
                        $named[$name] = \count($named) + 1;
                    }
                    $out .= '$' . $named[$name];
                    $i += \strlen($match[0]);

                    continue;
                }
            }

            $out .= $c;
            ++$i;
        }

        $arguments = $named !== [] ? array_map(static fn (string $name): string => ':' . $name, array_keys($named)) : array_fill(0, $positional, '?');

        return ['sql' => rtrim($out), 'arguments' => $arguments];
    }
}

<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Adapter\Database\PostgreSQL;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\PDO\Statement as PdoStatement;
use Doctrine\DBAL\Driver\Statement;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Util\Hasher;

/**
 * @internal
 */
#[Package('framework')]
class PlanCacheConnection extends AbstractConnectionMiddleware
{
    private const PREFIX = 'sw_';

    /**
     * @var array<string, true>|null names of the statements prepared on the backend, null until read from `pg_prepared_statements`
     */
    private ?array $known = null;

    /**
     * @var array<string, true>
     */
    private array $uncacheable = [];

    public function __construct(
        Connection $connection,
        private readonly int $limit,
    ) {
        parent::__construct($connection);
    }

    public function prepare(string $sql): Statement
    {
        $name = self::PREFIX . substr(Hasher::hash($sql, 'md5'), 0, 24);
        if (isset($this->uncacheable[$name])) {
            return parent::prepare($sql);
        }

        $rewritten = PlanCacheMiddleware::rewrite($sql);
        if ($rewritten === null) {
            $this->uncacheable[$name] = true;

            return parent::prepare($sql);
        }

        $known = $this->known();
        if (!isset($known[$name])) {
            if (\count($known) >= $this->limit) {
                $oldest = (string) array_key_first($known);
                unset($this->known[$oldest]);
                $this->exec('DEALLOCATE ' . $oldest);
            }
            try {
                parent::exec('PREPARE ' . $name . ' AS ' . $rewritten['sql']);
            } catch (\Throwable) {
                // run it unnamed, a real error shows up there
                $this->uncacheable[$name] = true;

                return parent::prepare($sql);
            }
            $this->known[$name] = true;
        }

        // EXECUTE can not take typed parameters from the extended protocol (PostgreSQL does not resolve `$1` inside a utility statement, not even with a cast),
        // so this one statement is sent with client side parameter quoting, as literals that PostgreSQL coerces to the parameter types of the prepared statement
        $execute = 'EXECUTE ' . $name . ($rewritten['arguments'] === [] ? '' : '(' . implode(', ', $rewritten['arguments']) . ')');
        $pdo = parent::getNativeConnection();
        if (!$pdo instanceof \PDO) {
            return parent::prepare($sql);
        }

        return new PlanCacheStatement(new PdoStatement($pdo->prepare($execute, [\PDO::ATTR_EMULATE_PREPARES => true])), function () use ($name): void {
            unset($this->known[$name]);
        });
    }

    public function exec(string $sql): int|string
    {
        $result = parent::exec($sql);

        // a changed table can invalidate the result type of cached plans, start over after a schema change
        if ($this->known !== [] && $this->known !== null && preg_match('/^\s*(alter|create|drop)\b/i', $sql)) {
            parent::exec('DEALLOCATE ALL');
            $this->known = [];
        }

        return $result;
    }

    /**
     * @return array<string, true>
     */
    private function known(): array
    {
        if ($this->known === null) {
            $this->known = [];
            $result = parent::query('SELECT name FROM pg_prepared_statements WHERE name LIKE \'sw\\_%\'');
            while (($name = $result->fetchOne()) !== false) {
                $this->known[(string) $name] = true;
            }
        }

        return $this->known;
    }
}

<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Adapter\Database\PostgreSQL;

use Shopware\Core\Framework\Log\Package;

/**
 * Remembers per connection which result columns of a statement are `uuid` columns, keyed by SQL text and column index.
 *
 * The SQL text only identifies the result types while the schema and the session state stay the same, so:
 * - every connection has its own instance (session-local objects such as temporary views differ per connection),
 * - every statement that can change what a name resolves to (DDL, `SET`, `DO`, `ROLLBACK`, ...) drops the entries of all connections in the process.
 *   Only statements known to leave types alone (`SELECT`, `INSERT`, `UPDATE`, `DELETE`, `WITH`, transaction and savepoint control) keep them.
 *
 * Not covered: schema changes made by another process while this one keeps running (long-running workers must restart after migrations,
 * as they must for the Shopware container anyway) and DDL run from inside a function called by a `SELECT`.
 *
 * @internal
 */
#[Package('framework')]
final class IdCodecColumnTypes
{
    private const LIMIT = 10000;

    private const KEEPS_TYPES = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'WITH', 'VALUES', 'BEGIN', 'START', 'COMMIT', 'SAVEPOINT', 'RELEASE', 'SHOW', 'EXPLAIN'];

    private static int $generation = 0;

    private int $seenGeneration;

    /**
     * @var array<string, array<int, bool>>
     */
    private array $uuidColumns = [];

    public function __construct()
    {
        $this->seenGeneration = self::$generation;
    }

    public function get(string $sql, int $index): ?bool
    {
        if ($this->seenGeneration !== self::$generation) {
            $this->uuidColumns = [];
            $this->seenGeneration = self::$generation;
        }

        return $this->uuidColumns[$sql][$index] ?? null;
    }

    public function set(string $sql, int $index, bool $isUuid): void
    {
        if (\count($this->uuidColumns) >= self::LIMIT) {
            $this->uuidColumns = [];
        }
        $this->uuidColumns[$sql][$index] = $isUuid;
    }

    /**
     * Called after a statement ran on the connection.
     */
    public function observe(string $sql): void
    {
        if (!\in_array(self::firstKeyword($sql), self::KEEPS_TYPES, true)) {
            self::invalidateAll();
        }
    }

    public static function invalidateAll(): void
    {
        ++self::$generation;
    }

    private static function firstKeyword(string $sql): string
    {
        // skip leading whitespace, comments (including the MySQL `#` comments Shopware puts in front of queries) and opening parentheses
        if (preg_match('/^(?:\s+|(?:--|#)[^\n]*(?:\n|$)|\/\*.*?\*\/|\()*([a-z]+)/isD', $sql, $match) !== 1) {
            return '';
        }

        return strtoupper($match[1]);
    }
}

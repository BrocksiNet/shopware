<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Adapter\Database\Sqlite;

use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\ParameterType;
use Shopware\Core\Framework\Log\Package;

/**
 * SQLite compares a BLOB never equal to TEXT. The DAL binds ids as bytes with different types (plain string on write, BINARY in IN lists on read),
 * so every binary parameter is bound the same way, as text of the raw bytes (pdo_sqlite stores them unchanged).
 *
 * pdo_sqlite can not bind a float: it sends the number as TEXT, and a number is never >= a TEXT (`HAVING SUM(..) >= ?`, `price > ?`).
 * A statement with a float parameter is prepared again with `CAST(? AS REAL)` around that placeholder.
 *
 * @internal
 */
#[Package('framework')]
class TextBindingStatement extends AbstractStatementMiddleware
{
    /**
     * @var array<int|string, array{mixed, ParameterType}>
     */
    private array $binds = [];

    /**
     * @var list<int|string>
     */
    private array $floats = [];

    /**
     * @param \Closure(string): Statement|null $prepare
     */
    public function __construct(Statement $statement, private readonly ?string $sql = null, private readonly ?\Closure $prepare = null)
    {
        parent::__construct($statement);
    }

    public function bindValue(int|string $param, mixed $value, ParameterType $type): void
    {
        if ($type === ParameterType::BINARY || $type === ParameterType::LARGE_OBJECT) {
            $type = ParameterType::STRING;
        }

        // PDO sends a string typed int or bool as TEXT, and TEXT '1' is not equal to INTEGER 1 for an expression without column affinity (`IFNULL(a, b) = ?`)
        if (\is_bool($value) || \is_int($value)) {
            $value = (int) $value;
            $type = ParameterType::INTEGER;
        }

        $this->binds[$param] = [$value, $type];
        if (\is_float($value)) {
            $this->floats[] = $param;
        }

        parent::bindValue($param, $value, $type);
    }

    public function execute(): Result
    {
        if ($this->floats === [] || $this->sql === null || $this->prepare === null) {
            return parent::execute();
        }

        $statement = ($this->prepare)(self::castPlaceholders($this->sql, $this->floats));
        foreach ($this->binds as $param => [$value, $type]) {
            $statement->bindValue($param, $value, $type);
        }

        return $statement->execute();
    }

    /**
     * @param list<int|string> $params positions (1 based) and names of the placeholders to wrap
     */
    private static function castPlaceholders(string $sql, array $params): string
    {
        $names = array_map(static fn (int|string $p): string => \is_string($p) ? ltrim($p, ':') : '', $params);
        $out = '';
        $position = 0;
        $length = \strlen($sql);
        for ($i = 0; $i < $length; ++$i) {
            $c = $sql[$i];
            if ($c === '\'' || $c === '"' || $c === '`') {
                $end = $i + 1;
                while ($end < $length && ($sql[$end] !== $c || ($end + 1 < $length && $sql[$end + 1] === $c && ++$end))) {
                    ++$end;
                }
                $out .= substr($sql, $i, $end - $i + 1);
                $i = $end;

                continue;
            }

            if ($c === '?') {
                ++$position;
                $out .= \in_array($position, $params, true) ? 'CAST(? AS REAL)' : '?';

                continue;
            }

            if ($c === ':' && $i + 1 < $length && (ctype_alpha($sql[$i + 1]) || $sql[$i + 1] === '_') && ($i === 0 || $sql[$i - 1] !== ':')) {
                preg_match('/\G\w+/', $sql, $m, 0, $i + 1);
                $out .= \in_array($m[0], $names, true) ? 'CAST(:' . $m[0] . ' AS REAL)' : ':' . $m[0];
                $i += \strlen($m[0]);

                continue;
            }

            $out .= $c;
        }

        return $out;
    }
}

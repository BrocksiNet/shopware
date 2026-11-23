<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Adapter\Database\PostgreSQL;

use Doctrine\DBAL\Driver\Middleware\AbstractResultMiddleware;
use Doctrine\DBAL\Driver\PDO\Result as PdoResult;
use Doctrine\DBAL\Driver\Result;
use Shopware\Core\Framework\Adapter\AdapterException;
use Shopware\Core\Framework\Log\Package;

/**
 * Converts the values of `uuid` result columns to the 16 byte form the PHP side uses.
 *
 * The decision is made by the column type, not by the value: a `text` column holding a uuid-looking string stays text.
 * Only a value that looks like a uuid triggers the type lookup, so columns that never hold one cost nothing.
 * The lookup (`PDOStatement::getColumnMeta()`, which queries the catalog and costs about one round trip) runs once per SQL string and column
 * and is cached per connection until a statement may have changed the types ({@see IdCodecColumnTypes}).
 *
 * @internal
 */
#[Package('framework')]
class IdCodecResult extends AbstractResultMiddleware
{
    private const UUID_OID = 2950;

    private ?\PDOStatement $statement = null;

    /**
     * @var array<string, int>|null
     */
    private ?array $indexByName = null;

    public function __construct(
        private readonly Result $result,
        private readonly string $sql,
        private readonly IdCodecColumnTypes $columnTypes,
    ) {
        parent::__construct($result);
    }

    public function fetchNumeric(): array|false
    {
        $row = parent::fetchNumeric();

        return $row === false ? false : $this->decodeNumeric($row);
    }

    public function fetchAssociative(): array|false
    {
        $row = parent::fetchAssociative();

        return $row === false ? false : $this->decodeAssociative($row);
    }

    public function fetchOne(): mixed
    {
        $value = parent::fetchOne();

        return IdCodecMiddleware::looksLikeUuid($value) && $this->isUuidColumn(0) ? IdCodecMiddleware::decode($value) : $value;
    }

    public function fetchAllNumeric(): array
    {
        return array_map(fn (array $row): array => $this->decodeNumeric($row), parent::fetchAllNumeric());
    }

    public function fetchAllAssociative(): array
    {
        return array_map(fn (array $row): array => $this->decodeAssociative($row), parent::fetchAllAssociative());
    }

    public function fetchFirstColumn(): array
    {
        $values = parent::fetchFirstColumn();
        foreach ($values as $i => $value) {
            if (IdCodecMiddleware::looksLikeUuid($value) && $this->isUuidColumn(0)) {
                $values[$i] = IdCodecMiddleware::decode($value);
            }
        }

        return $values;
    }

    /**
     * @param list<mixed> $row
     *
     * @return list<mixed>
     */
    private function decodeNumeric(array $row): array
    {
        foreach ($row as $index => $value) {
            if (IdCodecMiddleware::looksLikeUuid($value) && $this->isUuidColumn($index)) {
                $row[$index] = IdCodecMiddleware::decode($value);
            }
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function decodeAssociative(array $row): array
    {
        foreach ($row as $name => $value) {
            if (IdCodecMiddleware::looksLikeUuid($value) && $this->isUuidColumn($this->indexOf($name))) {
                $row[$name] = IdCodecMiddleware::decode($value);
            }
        }

        return $row;
    }

    /**
     * PDO keeps the last of several columns with the same name in an associative row, so the last index wins here as well.
     */
    private function indexOf(string $name): int
    {
        if ($this->indexByName === null) {
            $this->indexByName = [];
            for ($i = 0, $count = $this->columnCount(); $i < $count; ++$i) {
                $this->indexByName[$this->getColumnName($i)] = $i;
            }
        }

        return $this->indexByName[$name] ?? throw AdapterException::idCodecColumnTypeUnavailable(\sprintf('result column "%s" not found', $name));
    }

    private function isUuidColumn(int $index): bool
    {
        $known = $this->columnTypes->get($this->sql, $index);
        if ($known !== null) {
            return $known;
        }

        $meta = $this->statement()->getColumnMeta($index);
        $isUuid = \is_array($meta) && ($meta['pgsql:oid'] ?? null) === self::UUID_OID;

        $this->columnTypes->set($this->sql, $index, $isUuid);

        return $isUuid;
    }

    /**
     * The driver result API exposes no column types, so the PDO statement is read from the wrapped DBAL PDO result.
     * A result chain that does not end in a PDO result fails loudly: guessing by value would silently change text values.
     */
    private function statement(): \PDOStatement
    {
        if ($this->statement !== null) {
            return $this->statement;
        }

        $unwrap = \Closure::bind(static fn (AbstractResultMiddleware $result): Result => $result->wrappedResult, null, AbstractResultMiddleware::class);
        $result = $this->result;
        while ($result instanceof AbstractResultMiddleware) {
            $result = $unwrap($result);
        }

        if (!$result instanceof PdoResult) {
            throw AdapterException::idCodecColumnTypeUnavailable(\sprintf('the result chain ends in %s instead of a PDO result', $result::class));
        }

        return $this->statement = \Closure::bind(static fn (PdoResult $result): \PDOStatement => $result->statement, null, PdoResult::class)($result);
    }
}

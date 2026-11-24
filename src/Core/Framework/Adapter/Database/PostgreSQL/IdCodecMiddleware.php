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
 * Ids are native `uuid` columns on PostgreSQL, while the PHP side works with the 16 byte binary form it uses for MySQL `BINARY(16)`.
 * This middleware converts in both directions at the driver boundary, so no call site has to change:
 * bound 16 byte binary values become uuid strings, values of `uuid` result columns become 16 bytes.
 *
 * Parameters are converted by their bound type and value (the driver API exposes no parameter column types).
 * Results are converted by the column type only ({@see IdCodecResult}), so a uuid-looking text value stays text.
 *
 * @internal
 */
#[Package('framework')]
class IdCodecMiddleware implements Middleware
{
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';

    public function wrap(Driver $driver): Driver
    {
        return new class($driver) extends AbstractDriverMiddleware {
            public function connect(#[\SensitiveParameter] array $params): Connection
            {
                return new class(parent::connect($params)) extends AbstractConnectionMiddleware {
                    private readonly IdCodecColumnTypes $columnTypes;

                    public function __construct(Connection $connection)
                    {
                        parent::__construct($connection);
                        $this->columnTypes = new IdCodecColumnTypes();
                    }

                    public function prepare(string $sql): Statement
                    {
                        return new IdCodecStatement(parent::prepare($sql), $sql, $this->columnTypes);
                    }

                    public function query(string $sql): Result
                    {
                        $result = parent::query($sql);
                        $this->columnTypes->observe($sql);

                        return new IdCodecResult($result, $sql, $this->columnTypes);
                    }

                    public function exec(string $sql): int|string
                    {
                        $affected = parent::exec($sql);
                        $this->columnTypes->observe($sql);

                        return $affected;
                    }

                    public function rollBack(): void
                    {
                        parent::rollBack();
                        // a rolled back transaction can undo DDL
                        IdCodecColumnTypes::invalidateAll();
                    }
                };
            }
        };
    }

    /**
     * A value bound as binary with 16 bytes is an id.
     * A value bound as string is only treated as id when it is not valid UTF-8 or contains a control byte (NUL included), a 16 character text stays untouched.
     * About one random id in 65,000 is valid UTF-8 (all bytes below 0x80), the control byte rule catches nearly all of them, text with control bytes is not an id candidate in practice.
     * PostgreSQL text can not hold a NUL byte, so such a value can only be an id (e.g. the all-zero id).
     */
    public static function encode(mixed $value, bool $boundAsBinary): mixed
    {
        if (!\is_string($value) || \strlen($value) !== 16) {
            return $value;
        }

        if (!$boundAsBinary && self::isStrict()) {
            return $value;
        }
        if (!$boundAsBinary && mb_check_encoding($value, 'UTF-8') && preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value) !== 1) {
            return $value;
        }

        return bin2hex($value);
    }

    /**
     * `SHOPWARE_PG_ID_CODEC=strict` converts only parameters bound as BINARY or LARGE_OBJECT, `guess` (default) also converts untyped 16 byte strings that are not valid UTF-8 or contain a NUL byte.
     * The mode only affects parameters; results are always converted by column type.
     */
    public static function isStrict(): bool
    {
        return ($_SERVER['SHOPWARE_PG_ID_CODEC'] ?? $_ENV['SHOPWARE_PG_ID_CODEC'] ?? getenv('SHOPWARE_PG_ID_CODEC')) === 'strict';
    }

    /**
     * Cheap pre-check before the column type lookup in {@see IdCodecResult}.
     */
    public static function looksLikeUuid(mixed $value): bool
    {
        return \is_string($value) && \strlen($value) === 36 && preg_match(self::UUID_PATTERN, $value) === 1;
    }

    /**
     * Only call this for values of a `uuid` column, the type decides, not the shape of the value.
     */
    public static function decode(mixed $value): mixed
    {
        if (!self::looksLikeUuid($value)) {
            return $value;
        }

        return (string) hex2bin(str_replace('-', '', $value));
    }
}

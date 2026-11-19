<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Adapter\Database\Dialect;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Static counterpart of `Uuid::fromHexToBytes()` / `Uuid::fromBytesToHex()` for SQL code:
 * call sites pass and read ids as hex strings, this class binds them the way the active database stores them.
 *
 * - MySQL, MariaDB, SQLite: 16 raw bytes (`BINARY(16)`, `BLOB`), bound as `ParameterType::BINARY`.
 * - PostgreSQL: 32 character hex text, bound as `ParameterType::STRING`; PostgreSQL parses it into the native `uuid` without any conversion layer.
 *
 * Results are normalised by value, so a column that arrives as bytes (MySQL, or PostgreSQL while the id codec still runs) and one that arrives as uuid text (codec off) end up the same.
 * Static on purpose: the 300 call sites live in services without a dialect, the facade is what a Rector rule can rewrite to.
 *
 * @internal
 */
#[Package('framework')]
final class IdBinding
{
    private static ?bool $hexBinding = null;

    public static function useHexBinding(?bool $hex): void
    {
        self::$hexBinding = $hex;
    }

    public static function isHexBinding(): bool
    {
        return self::$hexBinding ??= SqlDialectFactory::getDefault()->idParameterType() === ParameterType::STRING;
    }

    public static function value(string $hex): string
    {
        return self::isHexBinding() ? strtolower($hex) : Uuid::fromHexToBytes($hex);
    }

    /**
     * @param array<string> $hexIds
     *
     * @return list<string>
     */
    public static function values(array $hexIds): array
    {
        return array_map(self::value(...), array_values($hexIds));
    }

    public static function type(): ParameterType
    {
        return self::isHexBinding() ? ParameterType::STRING : ParameterType::BINARY;
    }

    public static function listType(): ArrayParameterType
    {
        return self::isHexBinding() ? ArrayParameterType::STRING : ArrayParameterType::BINARY;
    }

    /**
     * SQL expression that selects an id column as lower case 32 character hex text (same as `SqlDialect::idToText()`).
     */
    public static function text(string $columnSql): string
    {
        return self::isHexBinding() ? \sprintf('REPLACE(%s::text, \'-\', \'\')', $columnSql) : \sprintf('LOWER(HEX(%s))', $columnSql);
    }

    /**
     * Id as read from a column: 16 bytes, uuid text with dashes or hex text, returned as lower case 32 character hex.
     */
    public static function toHex(string $id): string
    {
        return match (\strlen($id)) {
            16 => bin2hex($id),
            36 => str_replace('-', '', strtolower($id)),
            default => strtolower($id),
        };
    }

    /**
     * @param array<string> $ids
     *
     * @return list<string>
     */
    public static function toHexList(array $ids): array
    {
        return array_map(self::toHex(...), array_values($ids));
    }
}

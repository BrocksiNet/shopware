<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\DalV2\Capture;

use Shopware\Core\Framework\Log\Package;

/**
 * Appends every statement with its parameters as one JSON line to a file (option `sqllog=<file>`).
 * Binary values are written as `{"hex": "..."}`, so a plan script can bind them again.
 *
 * @internal
 */
#[Package('framework')]
final class SqlLog
{
    public static ?string $file = null;

    /**
     * @param array<int|string, array{mixed, string}> $params
     */
    public static function statement(string $sql, array $params, float $ms): void
    {
        if (self::$file === null) {
            return;
        }
        $encoded = [];
        foreach ($params as $key => [$value, $type]) {
            $encoded[$key] = ['v' => \is_string($value) && !mb_check_encoding($value, 'UTF-8') ? ['hex' => bin2hex($value)] : $value, 't' => $type];
        }
        self::write(['sql' => $sql, 'params' => $encoded, 'ms' => round($ms, 3)]);
    }

    public static function marker(string $name): void
    {
        self::write(['marker' => $name]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function write(array $row): void
    {
        if (self::$file !== null) {
            file_put_contents(self::$file, json_encode($row, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE) . "\n", \FILE_APPEND);
        }
    }
}

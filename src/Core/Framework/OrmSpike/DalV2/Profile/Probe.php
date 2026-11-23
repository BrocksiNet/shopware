<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\DalV2\Profile;

use Shopware\Core\Framework\Log\Package;

/**
 * hrtime probe with exclusive time per category: entering a category pauses the one below it on the stack.
 *
 * @internal
 */
#[Package('framework')]
final class Probe
{
    public static bool $on = false;

    /**
     * @var array<string, int>
     */
    public static array $ns = [];

    /**
     * @var array<string, int>
     */
    public static array $calls = [];

    /**
     * @var array<string, array{n: int, ns: int}>
     */
    public static array $sql = [];

    /**
     * @var list<string>
     */
    private static array $stack = [];

    private static int $last = 0;

    public static function reset(): void
    {
        self::$ns = [];
        self::$calls = [];
        self::$sql = [];
        self::$stack = [];
    }

    public static function statement(string $sql, int $ns): void
    {
        if (!self::$on) {
            return;
        }

        $key = substr((string) preg_replace('/\s+/', ' ', $sql), 0, 4000);
        self::$sql[$key] ??= ['n' => 0, 'ns' => 0];
        ++self::$sql[$key]['n'];
        self::$sql[$key]['ns'] += $ns;
    }

    public static function enter(string $category): void
    {
        if (!self::$on) {
            return;
        }

        $now = hrtime(true);
        if (self::$stack !== []) {
            $top = self::$stack[\count(self::$stack) - 1];
            self::$ns[$top] = (self::$ns[$top] ?? 0) + ($now - self::$last);
        }
        self::$calls[$category] = (self::$calls[$category] ?? 0) + 1;
        self::$stack[] = $category;
        self::$last = hrtime(true);
    }

    public static function leave(): void
    {
        if (!self::$on || self::$stack === []) {
            return;
        }

        $now = hrtime(true);
        $top = array_pop(self::$stack);
        self::$ns[$top] = (self::$ns[$top] ?? 0) + ($now - self::$last);
        self::$last = hrtime(true);
    }
}

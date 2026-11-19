<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Adapter\Database\Sqlite;

use Shopware\Core\Framework\Log\Package;

/**
 * MySQL functions SQLite does not ship, implemented in PHP and registered per connection.
 *
 * @internal
 */
#[Package('framework')]
class SqliteFunctions
{
    public static function register(\PDO $pdo): void
    {
        $create = static fn (string $name, callable $fn, int $args = -1) => $pdo->sqliteCreateFunction($name, $fn, $args, \PDO::SQLITE_DETERMINISTIC);

        $create('CONCAT', static function (...$args): ?string {
            foreach ($args as $arg) {
                if ($arg === null) {
                    return null;
                }
            }

            return implode('', array_map('strval', $args));
        });
        $create('CONCAT_WS', static function (?string $sep, ...$args): ?string {
            return $sep === null ? null : implode($sep, array_map('strval', array_filter($args, static fn ($a) => $a !== null)));
        });
        $create('IF', static fn ($c, $a, $b) => $c ? $a : $b, 3);
        $create('UNHEX', static fn (?string $s) => $s === null ? null : (ctype_xdigit($s) && \strlen($s) % 2 === 0 ? hex2bin($s) : null), 1);
        $create('FIND_IN_SET', static function (?string $needle, ?string $list): ?int {
            if ($needle === null || $list === null) {
                return null;
            }
            $pos = array_search($needle, explode(',', $list), true);

            return $pos === false ? 0 : $pos + 1;
        }, 2);
        $create('REGEXP', static fn (?string $pattern, ?string $subject) => $pattern === null || $subject === null ? null : (int) preg_match('~' . str_replace('~', '\~', $pattern) . '~iu', $subject), 2);
        $create('LPAD', static fn (?string $s, int $len, string $pad) => $s === null ? null : mb_substr(str_pad($s, $len, $pad, \STR_PAD_LEFT), 0, $len), 3);
        $create('UNIX_TIMESTAMP', static fn (?string $d = null) => $d === null ? time() : strtotime($d . ' UTC'));
        $create('NOW', static fn () => gmdate('Y-m-d H:i:s'), 0);
        $create('UTC_TIMESTAMP', static fn () => gmdate('Y-m-d H:i:s'));
        $create('CURDATE', static fn () => gmdate('Y-m-d'), 0);
        $create('GREATEST', static fn (...$a) => \in_array(null, $a, true) ? null : max($a));
        $create('LEAST', static fn (...$a) => \in_array(null, $a, true) ? null : min($a));
        $create('LOCATE', static function (string $needle, string $haystack, int $pos = 1): int {
            $p = mb_strpos($haystack, $needle, max(0, $pos - 1));

            return $p === false ? 0 : $p + 1;
        });
        $create('SUBSTRING_INDEX', static function (string $s, string $d, int $n): string {
            $parts = explode($d, $s);

            return $n >= 0 ? implode($d, \array_slice($parts, 0, $n)) : implode($d, \array_slice($parts, $n));
        }, 3);
        $create('DATE_FORMAT', static fn (?string $d, string $f) => $d === null ? null : self::dateFormat($d, $f), 2);
        $create('MD5', static fn (?string $s) => $s === null ? null : md5($s), 1);
        $create('SHA2', static fn (?string $s, int $bits) => $s === null ? null : hash('sha' . $bits, $s), 2);
        $create('RAND', static fn () => mt_rand() / mt_getrandmax(), 0);
    }

    private static function dateFormat(string $date, string $format): string
    {
        $map = ['%Y' => 'Y', '%m' => 'm', '%d' => 'd', '%H' => 'H', '%i' => 'i', '%s' => 's', '%y' => 'y', '%e' => 'j', '%c' => 'n', '%M' => 'F', '%b' => 'M', '%W' => 'l', '%a' => 'D', '%j' => 'z', '%T' => 'H:i:s', '%%' => '%'];

        return (new \DateTimeImmutable($date, new \DateTimeZone('UTC')))->format(strtr($format, $map));
    }
}

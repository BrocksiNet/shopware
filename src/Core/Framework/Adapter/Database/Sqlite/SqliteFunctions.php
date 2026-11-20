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
        $create = static fn (string $name, callable $fn, int $args = -1) => $pdo->sqliteCreateFunction($name, $fn, $args, \in_array(strtoupper($name), ['UUID', 'UUID_SHORT', 'RAND', 'NOW', 'UTC_TIMESTAMP', 'CURDATE', 'UNIX_TIMESTAMP', 'SLEEP'], true) ? 0 : \PDO::SQLITE_DETERMINISTIC);

        foreach (['utf8mb4_unicode_ci', 'utf8_unicode_ci', 'utf8mb4_unicode_520_ci', 'utf8mb4_0900_as_cs', 'utf8mb4_general_ci', 'utf8mb4_0900_ai_ci', 'utf8_general_ci', 'utf8mb4_bin'] as $collation) {
            $bin = $collation === 'utf8mb4_bin';
            $pdo->sqliteCreateCollation($collation, static fn (string $a, string $b): int => $bin ? strcmp($a, $b) : strcmp(mb_strtolower($a), mb_strtolower($b)));
        }

        // constraint name of the foreign key on `$column`, PRAGMA foreign_key_list does not know it
        $create('sw_fk_name', static function (?string $table, ?string $column) use ($pdo): ?string {
            $sql = $pdo->query('SELECT sql FROM sqlite_master WHERE type = \'table\' AND name = ' . $pdo->quote((string) $table))?->fetchColumn();
            if (\is_string($sql) && preg_match('/CONSTRAINT\s+`([^`]+)`\s+FOREIGN\s+KEY\s*\(([^)]*)\)/i', $sql . ' ', $m) === 1) {
                preg_match_all('/CONSTRAINT\s+`([^`]+)`\s+FOREIGN\s+KEY\s*\(([^)]*)\)/i', $sql, $all, \PREG_SET_ORDER);
                foreach ($all as $constraint) {
                    if (\in_array($column, array_map(static fn (string $c): string => trim($c, " `\n"), explode(',', $constraint[2])), true)) {
                        return $constraint[1];
                    }
                }
            }

            return null;
        }, 2);

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
        $create('JSON_UNQUOTE', static function (mixed $v): mixed {
            if (!\is_string($v) || $v === '' || $v[0] !== '"') {
                return $v;
            }

            return json_decode($v, true) ?? $v;
        }, 1);
        // round(20.055, 2) is 20.05 in SQLite (binary float) and 20.06 on MySQL (decimal), PHP pre-rounds like the decimal
        $create('ROUND', static fn (mixed $n) => $n === null ? null : round((float) $n), 1);
        $create('ROUND', static fn (mixed $n, mixed $d) => $n === null || $d === null ? null : round((float) $n, (int) $d), 2);
        // the built-in lower() folds ASCII only, the DAL string filters compare lower(column) with a multibyte lowered value
        $create('LOWER', static fn (?string $s) => $s === null ? null : mb_strtolower($s), 1);
        $create('CHAR_LENGTH', static fn (?string $s) => $s === null ? null : mb_strlen($s), 1);
        $create('CHARACTER_LENGTH', static fn (?string $s) => $s === null ? null : mb_strlen($s), 1);
        $create('REVERSE', static fn (?string $s) => $s === null ? null : implode('', array_reverse(mb_str_split($s))), 1);
        $create('DATABASE', static fn () => 'main', 0);
        $create('JSON_CONTAINS_PATH', static function (?string $json, string $mode, string ...$paths): ?int {
            if ($json === null) {
                return null;
            }
            $data = json_decode($json, true);
            foreach ($paths as $path) {
                $node = $data;
                $found = \is_array($node);
                foreach (array_filter(explode('.', ltrim($path, '$.'))) as $key) {
                    if (!\is_array($node) || !\array_key_exists($key, $node)) {
                        $found = false;
                        break;
                    }
                    $node = $node[$key];
                }
                if ($found && strtolower($mode) === 'one') {
                    return 1;
                }
                if (!$found && strtolower($mode) === 'all') {
                    return 0;
                }
            }

            return strtolower($mode) === 'all' ? 1 : 0;
        });
        $create('UUID', static fn () => \sprintf('%08x-%04x-%04x-%04x-%012x', random_int(0, 0xFFFFFFFF), random_int(0, 0xFFFF), random_int(0x4000, 0x4FFF), random_int(0x8000, 0xBFFF), random_int(0, 0xFFFFFFFFFFFF)), 0);
        $create('JSON_CONTAINS', static function (?string $target, ?string $candidate): ?int {
            if ($target === null || $candidate === null) {
                return null;
            }
            $t = json_decode($target, true);
            $c = json_decode($candidate, true);
            if (\is_array($t) && array_is_list($t)) {
                // an array candidate is contained when every element of it is (MySQL: JSON_CONTAINS('["a","b"]', '["a"]') is 1)
                if (\is_array($c) && array_is_list($c)) {
                    foreach ($c as $element) {
                        if (!\in_array($element, $t, false)) {
                            return 0;
                        }
                    }

                    return 1;
                }

                return (int) \in_array($c, $t, false);
            }

            return (int) ($t === $c);
        }, 2);
        $create('JSON_ARRAY_APPEND', static function (?string $json, string $path, mixed $value): ?string {
            $data = json_decode((string) $json, true);
            $v = \is_string($value) && ($d = json_decode($value, true)) !== null ? $d : $value;
            if ($path === '$') {
                $data = \is_array($data) && array_is_list($data) ? $data : [$data];
                $data[] = $v;
            }

            return json_encode($data);
        }, 3);
        $create('IF', static fn ($c, $a, $b) => $c ? $a : $b, 3);
        // SQLite hex(NULL) is '' while MySQL gives NULL, which breaks LOWER(HEX(parent_id)) === null checks
        $create('HEX', static fn (mixed $v) => $v === null ? null : strtoupper(bin2hex((string) $v)), 1);
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
        $create('NOW', static fn (?int $precision = null) => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(($precision ?? 0) > 0 ? 'Y-m-d H:i:s.v' : 'Y-m-d H:i:s'));
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
        $create('QUARTER', static fn (?string $d) => $d === null ? null : (int) ceil((int) (new \DateTimeImmutable($d, new \DateTimeZone('UTC')))->format('n') / 3), 1);
        $create('MD5', static fn (?string $s) => $s === null ? null : md5($s), 1);
        $create('SHA2', static fn (?string $s, int $bits) => $s === null ? null : hash('sha' . $bits, $s), 2);
        $create('RAND', static fn () => mt_rand() / mt_getrandmax(), 0);
    }

    private static function dateFormat(string $date, string $format): string
    {
        $map = ['%Y' => 'Y', '%m' => 'm', '%d' => 'd', '%H' => 'H', '%i' => 'i', '%s' => 's', '%y' => 'y', '%e' => 'j', '%v' => 'W', '%x' => 'o', '%c' => 'n', '%M' => 'F', '%b' => 'M', '%W' => 'l', '%a' => 'D', '%j' => 'z', '%T' => 'H:i:s', '%%' => '%'];

        return (new \DateTimeImmutable($date, new \DateTimeZone('UTC')))->format(strtr($format, $map));
    }
}

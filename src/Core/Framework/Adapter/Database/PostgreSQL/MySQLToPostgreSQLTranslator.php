<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Adapter\Database\PostgreSQL;

use Shopware\Core\Framework\Log\Package;

/**
 * Rewrites the lexical parts of MySQL flavoured SQL that PostgreSQL does not understand.
 * The input is expected to follow MySQL rules, which means a double quoted token is a string literal, not an identifier.
 *
 * - `identifier`  => "identifier"
 * - "string"      => 'string'
 * - 'back\\slash' => E'back\\slash'
 * - # comment     => removed
 * - a <=> b        => a IS NOT DISTINCT FROM b
 * - AS 'alias'     => AS "alias"
 * - AS mixedCase   => column name restored in the result, see translate()
 * - identifiers longer than 63 bytes => shortened, name restored in the result
 *
 * @internal
 */
#[Package('framework')]
class MySQLToPostgreSQLTranslator
{
    private const CACHE_LIMIT = 5000;

    private const MAX_IDENTIFIER_LENGTH = 63;

    /**
     * Single words that are replaced, key in lower case.
     * The functions they refer to are defined in mysql-compat.sql.
     */
    private const KEYWORDS = [
        // GROUP_CONCAT(x SEPARATOR ',') => group_concat(x , ',')
        'separator' => ',',
    ];

    /**
     * @var array<string, array{string, array<string, string>}>
     */
    private array $cache = [];

    /**
     * Returns the rewritten statement and a map of lower cased to original spelling for every unquoted mixed case word.
     * PostgreSQL folds unquoted identifiers to lower case, MySQL keeps the spelling of an alias like `AS languageId`.
     * The map is used to restore the column names of a result.
     *
     * @return array{string, array<string, string>}
     */
    public function translate(string $sql): array
    {
        if (isset($this->cache[$sql])) {
            return $this->cache[$sql];
        }

        if (\count($this->cache) >= self::CACHE_LIMIT) {
            $this->cache = [];
        }

        return $this->cache[$sql] = $this->rewrite($sql);
    }

    /**
     * @return array{string, array<string, string>}
     */
    private function rewrite(string $sql): array
    {
        $caseMap = [];
        $out = '';
        $length = \strlen($sql);
        $i = 0;
        // one entry per open parenthesis, true for the argument list of jsonb_build_object / jsonb_build_array
        $parens = [];
        // MySQL returns the column names of information_schema in the spelling of the statement, usually upper case
        $restoreUpper = stripos($sql, 'information_schema') !== false;
        $jsonCall = false;

        while ($i < $length) {
            $char = $sql[$i];

            if ($char === '\'' || $char === '"') {
                [$content, $i] = $this->readString($sql, $i, $char);
                // MySQL accepts a string literal as column alias: SELECT x AS '0'
                $isAlias = preg_match('/\bAS\s+$/i', substr($out, -12)) === 1;
                if ($isAlias) {
                    $out .= $this->identifier($content, $caseMap);
                } elseif ($this->followsJsonExtract($out)) {
                    // JSON_EXTRACT(...) = 'text': MySQL compares with the JSON string, PostgreSQL would parse the literal as JSON
                    $out .= 'to_jsonb(' . $this->stringLiteral($content) . '::text)';
                } else {
                    $out .= $this->stringLiteral($content);
                }

                continue;
            }

            if ($char === '`') {
                $end = $i + 1;
                $name = '';
                while ($end < $length) {
                    if ($sql[$end] === '`') {
                        if (($sql[$end + 1] ?? '') !== '`') {
                            break;
                        }
                        ++$end;
                    }
                    $name .= $sql[$end];
                    ++$end;
                }
                $out .= $this->identifier($name, $caseMap);
                $i = $end + 1;

                continue;
            }

            if ($char === '#') {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $length : $end;

                continue;
            }

            if ($char === '-' && ($sql[$i + 1] ?? '') === '-') {
                $end = strpos($sql, "\n", $i);
                $end = $end === false ? $length : $end;
                $out .= substr($sql, $i, $end - $i);
                $i = $end;

                continue;
            }

            if ($char === '/' && ($sql[$i + 1] ?? '') === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $end = $end === false ? $length : $end + 2;
                $out .= substr($sql, $i, $end - $i);
                $i = $end;

                continue;
            }

            // null safe equals
            if ($char === '<' && substr($sql, $i, 3) === '<=>') {
                $out .= ' IS NOT DISTINCT FROM ';
                $i += 3;

                continue;
            }

            // @@FOREIGN_KEY_CHECKS and friends, PostgreSQL always enforces constraints
            if ($char === '@' && preg_match('/\G@@(?:(?:SESSION|GLOBAL)\.)?(foreign_key_checks|unique_checks|sql_mode|version)\b/i', $sql, $variable, 0, $i) === 1) {
                $out .= match (strtolower($variable[1])) {
                    'sql_mode' => '\'\'',
                    'version' => 'current_setting(\'server_version\')',
                    default => '1',
                };
                $i += \strlen($variable[0]);

                continue;
            }

            // 0x0fa91ce3... (16 byte hex literal) is an id, ids are uuid on PostgreSQL
            if ($char === '0' && ($sql[$i + 1] ?? '') === 'x' && preg_match('/\G0x([0-9a-fA-F]{32})(?![0-9A-Za-z_])/', $sql, $hex, 0, $i) === 1 && ($i === 0 || !preg_match('/[A-Za-z0-9_$]/', $sql[$i - 1]))) {
                $out .= '\'' . strtolower($hex[1]) . '\'::uuid';
                $i += 34;

                continue;
            }

            if (ctype_alpha($char) || $char === '_') {
                $wordLength = strspn($sql, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_$', $i);
                $word = substr($sql, $i, $wordLength);
                $lower = strtolower($word);
                if (isset(self::KEYWORDS[$lower])) {
                    $out .= self::KEYWORDS[$lower];
                    $i += $wordLength;

                    continue;
                }
                // x REGEXP y => x ~* y, x NOT REGEXP y => x !~* y (case insensitive like the MySQL default collation, COLLATE "C" because regular expressions reject the nondeterministic "ci" collation)
                if ($lower === 'regexp' || $lower === 'rlike') {
                    $out = preg_match('/\bNOT\s+$/i', $out, $not) === 1 ? substr($out, 0, -\strlen($not[0])) . ' COLLATE "C" !~* ' : $out . ' COLLATE "C" ~* ';
                    $i += $wordLength;

                    continue;
                }
                // JSON_OBJECT(k, v, ...) and JSON_ARRAY(...) => jsonb_build_object / jsonb_build_array, both take any argument types
                if (($lower === 'json_object' || $lower === 'json_array') && (ltrim(substr($sql, $i + $wordLength, 8))[0] ?? '') === '(') {
                    $out .= $lower === 'json_object' ? 'jsonb_build_object' : 'jsonb_build_array';
                    $jsonCall = true;
                    $i += $wordLength;

                    continue;
                }
                // CAST(x AS SIGNED [INTEGER]) / AS UNSIGNED => AS bigint
                if (($lower === 'signed' || $lower === 'unsigned') && preg_match('/\bAS\s+$/i', substr($out, -12)) === 1) {
                    $out .= 'bigint';
                    $i += $wordLength;
                    if (preg_match('/\G\s+INTEGER\b/i', $sql, $integer, 0, $i) === 1) {
                        $i += \strlen($integer[0]);
                    }

                    continue;
                }
                // CONCAT(:a, 'x') takes arguments of any type, a bare placeholder gets text
                if (($lower === 'concat' || $lower === 'concat_ws') && (ltrim(substr($sql, $i + $wordLength, 8))[0] ?? '') === '(') {
                    $out .= $word;
                    $jsonCall = true;
                    $i += $wordLength;

                    continue;
                }
                // JSON_EXTRACT(...) IN ('a', :b): the values are strings, PostgreSQL would parse them as JSON
                if ($lower === 'json_extract' && $this->isFollowedByIn($sql, $i + $wordLength)) {
                    $out .= 'json_extract_text';
                    $i += $wordLength;

                    continue;
                }
                // `user` is a valid unquoted table name on MySQL and a reserved word on PostgreSQL
                if ($lower === 'user' && ($i === 0 || $sql[$i - 1] !== ':') && (ltrim(substr($sql, $i + $wordLength, 8))[0] ?? '') !== '(') {
                    $out .= '"user"';
                    $i += $wordLength;

                    continue;
                }
                if ($word !== $lower && ($word !== strtoupper($word) || $restoreUpper)) {
                    $caseMap[$lower] ??= $word;
                }
                $out .= $word;
                $i += $wordLength;

                continue;
            }

            // JSON_EXTRACT(...) = :value, the bound value is a string
            if (($char === '?' || ($char === ':' && preg_match('/\G:[A-Za-z_]\w*/', $sql, $named, 0, $i) === 1 && ($sql[$i - 1] ?? '') !== ':')) && $this->followsJsonExtract($out)) {
                $name = $char === '?' ? '?' : $named[0];
                $out .= 'to_jsonb(' . $name . '::text)';
                $i += \strlen($name);

                continue;
            }

            if ($char === '(') {
                $parens[] = $jsonCall;
                $jsonCall = false;
            } elseif ($char === ')') {
                array_pop($parens);
            } elseif (($char === ':' || $char === '?') && ($parens[\count($parens) - 1] ?? false) && \in_array(substr(rtrim($out), -1), ['(', ','], true)) {
                // the type of a bare placeholder in a JSON function can not be determined by PostgreSQL, MySQL binds it as a string as well
                $name = $char === ':' ? ':' . substr($sql, $i + 1, strspn($sql, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_', $i + 1)) : '?';
                if ($name !== ':') {
                    $out .= $name . '::text';
                    $i += \strlen($name);

                    continue;
                }
            }

            $out .= $char;
            ++$i;
        }

        return [$out, $caseMap];
    }

    /**
     * Returns the raw content between the quotes (escape sequences untouched, doubled quotes reduced) and the offset behind the literal.
     *
     * @return array{string, int}
     */
    private function readString(string $sql, int $start, string $quote): array
    {
        $length = \strlen($sql);
        $content = '';
        $i = $start + 1;

        while ($i < $length) {
            $char = $sql[$i];

            if ($char === '\\' && $i + 1 < $length) {
                $content .= $char . $sql[$i + 1];
                $i += 2;

                continue;
            }

            if ($char === $quote) {
                if (($sql[$i + 1] ?? '') === $quote) {
                    $content .= $quote;
                    $i += 2;

                    continue;
                }

                return [$content, $i + 1];
            }

            $content .= $char;
            ++$i;
        }

        return [$content, $length];
    }

    /**
     * PostgreSQL cuts identifiers after 63 bytes, MySQL allows 256 characters for an alias.
     * The DAL builds aliases like `order_delivery.shippingOrderAddress.country.translation.addressFormat`,
     * cutting them could make two aliases equal, so a longer name is replaced by a prefix plus a hash.
     * The replacement is the same everywhere in the statement and the map restores the name in the result.
     *
     * @param array<string, string> $caseMap
     */
    private function identifier(string $name, array &$caseMap): string
    {
        if (\strlen($name) > self::MAX_IDENTIFIER_LENGTH) {
            $short = substr($name, 0, self::MAX_IDENTIFIER_LENGTH - 17) . '~' . substr(md5($name), 0, 16);
            $caseMap[$short] = $name;
            $name = $short;
        }

        return '"' . str_replace('"', '""', $name) . '"';
    }

    /**
     * True when the call that starts at $offset (its opening parenthesis) is followed by IN (
     */
    private function isFollowedByIn(string $sql, int $offset): bool
    {
        $length = \strlen($sql);
        $offset += \strspn($sql, " \t\r\n", $offset);
        if (($sql[$offset] ?? '') !== '(') {
            return false;
        }

        $depth = 0;
        for ($i = $offset; $i < $length; ++$i) {
            $char = $sql[$i];
            if ($char === '\'' || $char === '"' || $char === '`') {
                [, $i] = $this->readString($sql, $i, $char);
                --$i;
            } elseif ($char === '(') {
                ++$depth;
            } elseif ($char === ')' && --$depth === 0) {
                return preg_match('/\G\s+IN\s*\(/i', $sql, $m, 0, $i + 1) === 1;
            }
        }

        return false;
    }

    /**
     * True when the output ends with `JSON_EXTRACT(...) =` (or !=, <>).
     */
    private function followsJsonExtract(string $out): bool
    {
        if (preg_match('/\)\s*(?:=|!=|<>)\s*$/', $out, $m) !== 1) {
            return false;
        }

        $i = \strlen($out) - \strlen($m[0]);
        $depth = 0;
        for (; $i >= 0; --$i) {
            if ($out[$i] === ')') {
                ++$depth;
            } elseif ($out[$i] === '(' && --$depth === 0) {
                return preg_match('/\bjson_extract\s*$/i', substr($out, 0, $i)) === 1;
            }
        }

        return false;
    }

    private function stringLiteral(string $content): string
    {
        $hasEscapes = str_contains($content, '\\');

        // Inside the content a quote is either escaped by a backslash already or has to be doubled
        $escaped = '';
        $length = \strlen($content);
        for ($i = 0; $i < $length; ++$i) {
            if ($content[$i] === '\\' && $i + 1 < $length) {
                $escaped .= $content[$i] . $content[$i + 1];
                ++$i;

                continue;
            }
            $escaped .= $content[$i] === '\'' ? '\'\'' : $content[$i];
        }

        return ($hasEscapes ? ' E\'' : '\'') . $escaped . '\'';
    }
}

<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Adapter\Database\PostgreSQL;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Util\Hasher;

/**
 * Rewrites the lexical parts of MySQL flavoured SQL that PostgreSQL does not understand.
 * The input is expected to follow MySQL rules, which means a double quoted token is a string literal, not an identifier.
 *
 * - `identifier`  => "identifier"
 * - "string"      => 'string'
 * - 'back\\slash' => E'back\\slash'
 * - # comment     => removed (not the operators `#>` and `#>>`)
 * - "identifier" => kept as identifier when the position only allows one, see isQuotedIdentifier()
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

    private const SHARED_CACHE_MAX_LENGTH = 65536;

    private const SHARED_CACHE_TTL = 86400;

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

        // the array lives as long as the process, a PHP-FPM request starts with the rewrite of every statement again, APCu keeps the result between requests
        $shared = \function_exists('apcu_fetch') && \strlen($sql) <= self::SHARED_CACHE_MAX_LENGTH;
        $key = $shared ? 'sw_pg_translation_' . self::sharedVersion() . Hasher::hash($sql) : '';
        if ($shared) {
            $hit = apcu_fetch($key, $success);
            if ($success) {
                return $this->cache[$sql] = $hit;
            }
        }

        $result = $this->cache[$sql] = $this->rewrite($sql);
        if ($shared) {
            apcu_store($key, $result, self::SHARED_CACHE_TTL);
        }

        return $result;
    }

    /**
     * The key of the shared cache changes with the translator code, a deployment must not read translations of the old rules.
     */
    private static function sharedVersion(): string
    {
        static $version = null;

        return $version ??= (string) filemtime(__FILE__) . '_';
    }

    /**
     * MySQL: INTERVAL :days DAY (a number or placeholder and a unit), PostgreSQL only knows INTERVAL '1 day'.
     * The result is `:days * INTERVAL '1 day'`, DATE_ADD and DATE_SUB take it (see mysql-compat.sql).
     */
    private function rewriteInterval(string $sql): string
    {
        return preg_replace_callback(
            '/\bINTERVAL\s+(:[A-Za-z_]\w*|\?|-?\d+)\s+(YEAR|QUARTER|MONTH|WEEK|DAY|HOUR|MINUTE|SECOND)\b/i',
            static fn (array $m): string => '(' . $m[1] . ' * INTERVAL \'' . (strtoupper($m[2]) === 'QUARTER' ? '3 month' : '1 ' . strtolower($m[2])) . '\')',
            $sql
        ) ?? $sql;
    }

    /**
     * GROUP_CONCAT([DISTINCT] expr [ORDER BY ...] [SEPARATOR sep]) puts the separator last, the aggregate takes it as second
     * argument in front of ORDER BY: group_concat(expr, sep ORDER BY ...).
     * Left as it is, the separator would silently become one more sort key.
     * ORDER BY NULL is a no-op on MySQL and invalid together with DISTINCT, it is dropped.
     */
    private function moveGroupConcatSeparator(string $sql): string
    {
        $offset = 0;
        while (preg_match('/\bGROUP_CONCAT\s*\(/i', $sql, $match, \PREG_OFFSET_CAPTURE, $offset) === 1) {
            $start = $match[0][1] + \strlen($match[0][0]);
            $offset = $start;
            $end = $this->closingParenthesis($sql, $start);
            if ($end === null) {
                break;
            }

            $body = substr($sql, $start, $end - $start);
            preg_match_all('/\bORDER\s+BY\b/i', $body, $orderMatches, \PREG_OFFSET_CAPTURE);
            $orderBy = $this->topLevelWord($body, $orderMatches[0]);
            if ($orderBy === null) {
                continue;
            }
            preg_match_all('/\bSEPARATOR\b/i', $body, $separatorMatches, \PREG_OFFSET_CAPTURE);
            $separator = $this->topLevelWord($body, $separatorMatches[0]);

            $expression = rtrim(substr($body, 0, $orderBy[0]));
            $order = trim(substr($body, $orderBy[0] + $orderBy[1], $separator === null || $separator[0] < $orderBy[0] ? null : $separator[0] - $orderBy[0] - $orderBy[1]));
            $rewritten = $expression
                . ($separator === null ? '' : ' SEPARATOR ' . trim(substr($body, $separator[0] + $separator[1])))
                . (strtoupper($order) === 'NULL' ? '' : ' ORDER BY ' . $order);

            $sql = substr($sql, 0, $start) . $rewritten . substr($sql, $end);
            $offset = $start + \strlen($rewritten);
        }

        return $sql;
    }

    private function closingParenthesis(string $sql, int $start): ?int
    {
        $depth = 1;
        $length = \strlen($sql);
        for ($i = $start; $i < $length; ++$i) {
            $char = $sql[$i];
            if ($char === '\'' || $char === '"' || $char === '`') {
                [, $i] = $this->readString($sql, $i, $char);
                --$i;
            } elseif ($char === '(') {
                ++$depth;
            } elseif ($char === ')' && --$depth === 0) {
                return $i;
            }
        }

        return null;
    }

    /**
     * @param array<int, array{string, int}> $candidates matches of a preg_match_all call with PREG_OFFSET_CAPTURE
     *
     * @return array{int, int}|null position and length of the first match outside of strings and nested parentheses
     */
    private function topLevelWord(string $body, array $candidates): ?array
    {
        foreach ($candidates as [$text, $position]) {
            $depth = 0;
            $quote = null;
            for ($i = 0; $i < $position; ++$i) {
                $char = $body[$i];
                if ($quote !== null) {
                    if ($char === '\\' && $quote === '\'') {
                        ++$i;
                    } elseif ($char === $quote) {
                        $quote = null;
                    }
                } elseif ($char === '\'' || $char === '"' || $char === '`') {
                    $quote = $char;
                } elseif ($char === '(') {
                    ++$depth;
                } elseif ($char === ')') {
                    --$depth;
                }
            }
            if ($depth === 0 && $quote === null) {
                return [$position, \strlen($text)];
            }
        }

        return null;
    }

    /**
     * @return array{string, array<string, string>}
     */
    private function rewrite(string $sql): array
    {
        if (stripos($sql, 'group_concat') !== false) {
            $sql = $this->moveGroupConcatSeparator($sql);
        }

        if (stripos($sql, 'interval') !== false) {
            $sql = $this->rewriteInterval($sql);
        }

        $caseMap = [];
        $out = '';
        $length = \strlen($sql);
        $i = 0;
        // one entry per open parenthesis, true for the argument list of jsonb_build_object / jsonb_build_array
        $parens = [];
        // MySQL returns the column names of information_schema in the spelling of the statement, usually upper case
        $restoreUpper = stripos($sql, 'information_schema') !== false;
        $jsonCall = false;
        // true inside the column list of INSERT INTO t (...) and ON CONFLICT (...)
        $columnList = false;

        while ($i < $length) {
            $char = $sql[$i];

            if ($char === '\'' || $char === '"') {
                [$content, $i] = $this->readString($sql, $i, $char);
                // MySQL accepts a string literal as column alias: SELECT x AS '0'
                $isAlias = preg_match('/\bAS\s+$/iD', substr($out, -12)) === 1;
                if ($isAlias || ($char === '"' && $this->isQuotedIdentifier($content, $sql, $i, $out, $columnList))) {
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

            if ($char === '(') {
                $columnList = preg_match('/(\bINTO\s+(?:"(?:[^"]|"")+"|`[^`]+`|[\w.]+)|\bCONFLICT)\s*$/iD', substr($out, -200)) === 1;
            } elseif ($char === ')') {
                $columnList = false;
            }

            // `#>` and `#>>` are PostgreSQL JSON operators, every other `#` starts a MySQL comment
            if ($char === '#' && ($sql[$i + 1] ?? '') === '>') {
                $out .= $char;
                ++$i;

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
                    $out = preg_match('/\bNOT\s+$/iD', $out, $not) === 1 ? substr($out, 0, -\strlen($not[0])) . ' COLLATE "C" !~* ' : $out . ' COLLATE "C" ~* ';
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
                if (($lower === 'signed' || $lower === 'unsigned') && preg_match('/\bAS\s+$/iD', substr($out, -12)) === 1) {
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
                if ($lower === 'user' && ($i === 0 || $sql[$i - 1] !== ':') && ((ltrim(substr($sql, $i + $wordLength, 8))[0] ?? '') !== '(' || preg_match('/\b(?:INTO|UPDATE|TABLE|JOIN)\s+$/iD', $out) === 1)) {
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
            $name = null;
            if ($char === '?') {
                $name = '?';
            } elseif ($char === ':' && ($sql[$i - 1] ?? '') !== ':' && preg_match('/\G:[A-Za-z_]\w*/', $sql, $named, 0, $i) === 1) {
                $name = $named[0];
            }
            if ($name !== null && $this->followsJsonExtract($out)) {
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

        return [$out, array_filter($caseMap, static fn (string $spelling, string $lower): bool => $spelling !== $lower, \ARRAY_FILTER_USE_BOTH)];
    }

    /**
     * Returns the raw content between the quotes (escape sequences untouched, doubled quotes reduced) and the offset behind the literal.
     *
     * @return array{string, int}
     */
    /**
     * A double quoted token is a string literal in MySQL and an identifier in PostgreSQL.
     * It is read as identifier when it looks like one and the position only allows an identifier:
     * next to a dot, after an identifier or `)` (alias without AS), after FROM / JOIN / INTO / UPDATE / TABLE / SET / BY / DISTINCT / WHERE / AND / OR / ON / HAVING / SELECT, in a column list, or before AS / FROM / ASC / DESC, in select, ORDER BY and GROUP BY lists.
     * Quotes written by `PostgreSqlDialect::quoteIdentifier()` always end up in such a position.
     */
    private function isQuotedIdentifier(string $content, string $sql, int $end, string $out, bool $columnList): bool
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_$.~-]*$/D', $content) !== 1) {
            return false;
        }

        // a cast written for PostgreSQL only follows a column: "a"::text
        if (substr($sql, $end, 2) === '::') {
            return true;
        }

        $trimmed = rtrim($out);
        // an alias without AS follows an identifier or a subquery: FROM "t" "alias", JOIN (SELECT ...) "alias"
        if (str_ends_with($trimmed, '.') || ($sql[$end] ?? '') === '.' || str_ends_with($trimmed, '"') || str_ends_with($trimmed, ')')) {
            return true;
        }

        if ($columnList && ($trimmed === '' || \in_array(substr($trimmed, -1), ['(', ','], true))) {
            return true;
        }

        if (preg_match('/\b(?:FROM|JOIN|INTO|UPDATE|TABLE|SET|BY|DISTINCT|ONLY|EXISTS|WHERE|AND|OR|ON|HAVING|SELECT)$/iD', $trimmed) === 1) {
            return true;
        }

        $next = substr($sql, $end, 12);
        if (preg_match('/^\s*(?:ASC|DESC|AS|FROM)\b/i', $next) === 1) {
            return true;
        }

        // an item of a select list like `"a.b", "a.b" AS "c"` and of ORDER BY / GROUP BY lists
        if (str_ends_with($trimmed, ',') && (str_contains($content, '.') || preg_match('/\b(?:ORDER|GROUP)\s+BY\s+[^()]*$/iD', substr($trimmed, -300)) === 1)) {
            return true;
        }

        // the first argument of an aggregate or null handling function is a column: COALESCE("a", '{}'), MAX("a"), JSON_SET("a", ...)
        if (preg_match('/\b(?:COALESCE|IFNULL|NULLIF|LOWER|UPPER|LENGTH|MAX|MIN|SUM|AVG|COUNT|JSON_\w+|CAST)\($/iD', $trimmed) === 1) {
            return true;
        }

        // a column of an UPDATE ... SET list: SET "a" = ?, "b" = ?
        if (str_ends_with($trimmed, ',') && preg_match('/^\s*=(?!=)/', substr($sql, $end, 6)) === 1) {
            $setPos = strripos($trimmed, ' SET ');
            if ($setPos !== false && preg_match('/\b(?:WHERE|SELECT|FROM)\b/i', substr($trimmed, $setPos)) !== 1) {
                return true;
            }
        }

        // the first argument of a function that is a dotted DAL alias: COALESCE("a.b.name", "a.c.name")
        if (str_ends_with($trimmed, '(') && str_contains($content, '.') && preg_match('/\b(?:IN|VALUES|LIKE)\s*\($/iD', $trimmed) !== 1 && preg_match('/\w\($/D', $trimmed) === 1) {
            return true;
        }

        // an item of a select list that has no FROM yet: SELECT 1, "a", "b" FROM
        if (\in_array(substr($trimmed, -1), [','], true) && preg_match('/^\s*(?:,|FROM\b)/i', substr($sql, $end, 12)) === 1) {
            $selectPos = strripos($trimmed, 'SELECT');
            if ($selectPos !== false && preg_match('/\bFROM\b/i', substr($trimmed, $selectPos)) !== 1) {
                return true;
            }
        }

        // a tuple comparison: WHERE ("a", "b") IN (...)
        if (\in_array(substr($trimmed, -1), ['(', ','], true) && preg_match('/^[^()]*\)\s*(?:=|IN\b|NOT\s+IN\b)/i', substr($sql, $end, 200)) === 1) {
            $open = strrpos($trimmed, '(');
            if ($open !== false && preg_match('/(?:\b(?:WHERE|AND|OR|ON|HAVING)|[(,])\s*$/i', substr($trimmed, 0, $open)) === 1 && !str_contains(substr($trimmed, $open), ')')) {
                return true;
            }
        }

        // a single column compared in parentheses: WHERE ("a" IN (?)) AND ("b" = ?)
        if (str_ends_with($trimmed, '(') && preg_match('/(?:\b(?:WHERE|AND|OR|ON|HAVING|NOT)|[(,])\s*$/i', substr($trimmed, 0, -1)) === 1
            && preg_match('/^\s*(?:IN\b|NOT\s+IN\b|IS\b|=(?!=)|<>|!=|<=?|>=?|LIKE\b|BETWEEN\b)/i', substr($sql, $end, 12)) === 1) {
            return true;
        }

        return false;
    }

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
            $short = substr($name, 0, self::MAX_IDENTIFIER_LENGTH - 17) . '~' . substr(Hasher::hash($name, 'md5'), 0, 16);
            $caseMap[$short] = $name;
            $name = $short;
        } elseif ($name === strtolower($name)) {
            // a quoted lower case name keeps its spelling, a keyword like WHERE elsewhere in the statement must not rename it
            $caseMap[$name] = $name;
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
        if (preg_match('/\)\s*(?:=|!=|<>)\s*$/D', $out, $m) !== 1) {
            return false;
        }

        $i = \strlen($out) - \strlen($m[0]);
        $depth = 0;
        for (; $i >= 0; --$i) {
            if ($out[$i] === ')') {
                ++$depth;
            } elseif ($out[$i] === '(' && --$depth === 0) {
                return preg_match('/\bjson_extract\s*$/iD', substr($out, 0, $i)) === 1;
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

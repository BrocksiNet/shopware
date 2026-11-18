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
 * - AS mixedCase   => column name restored in the result, see translate()
 *
 * @internal
 */
#[Package('framework')]
class MySQLToPostgreSQLTranslator
{
    private const CACHE_LIMIT = 5000;

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

        while ($i < $length) {
            $char = $sql[$i];

            if ($char === '\'' || $char === '"') {
                [$content, $i] = $this->readString($sql, $i, $char);
                $out .= $this->stringLiteral($content);

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
                $out .= '"' . str_replace('"', '""', $name) . '"';
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

            if (ctype_alpha($char) || $char === '_') {
                $wordLength = strspn($sql, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_$', $i);
                $word = substr($sql, $i, $wordLength);
                $lower = strtolower($word);
                if (isset(self::KEYWORDS[$lower])) {
                    $out .= self::KEYWORDS[$lower];
                    $i += $wordLength;

                    continue;
                }
                if ($word !== $lower && $word !== strtoupper($word)) {
                    $caseMap[$lower] ??= $word;
                }
                $out .= $word;
                $i += $wordLength;

                continue;
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

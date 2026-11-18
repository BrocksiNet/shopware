<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Adapter\Database\PostgreSQL;

use Shopware\Core\Framework\Log\Package;

/**
 * PostgreSQL can not infer the type of a bare placeholder in the select list of INSERT ... SELECT, MySQL does not care.
 * The placeholder gets the type of the column it is inserted into.
 *
 *   INSERT INTO t (a, b) SELECT x, :value FROM s => INSERT INTO t (a, b) SELECT x, CAST(:value AS <type of t.b>) FROM s
 *
 * Expects a statement that already went through the MySQLToPostgreSQLTranslator (identifiers in double quotes).
 *
 * @internal
 */
#[Package('framework')]
class InsertSelectRewriter
{
    private const IDENT = '"(?:[^"]|"")+"|[A-Za-z0-9_]+';

    /**
     * @param \Closure(string, string): ?string $getColumnType
     */
    public static function rewrite(string $sql, \Closure $getColumnType): string
    {
        if (preg_match('/^(\s*(?:INSERT(?:\s+IGNORE)?|REPLACE)\s+INTO\s+(' . self::IDENT . ')\s*\(([^)]*)\)\s*SELECT\s+)(.*)$/isD', $sql, $m) !== 1) {
            return $sql;
        }

        [, $head, $table, $columnList, $rest] = $m;
        $from = UpdateRewriter::findWord($rest, 'FROM', 0);
        $selectList = $from === null ? $rest : substr($rest, 0, $from);
        $items = DdlRewriter::splitTopLevel($selectList);
        $columns = array_map(static fn (string $column): string => trim(trim($column), '"'), DdlRewriter::splitTopLevel($columnList));
        if (\count($items) !== \count($columns)) {
            return $sql;
        }

        $changed = false;
        foreach ($items as $index => $item) {
            if (preg_match('/^(\s*)(:[A-Za-z_]\w*|\?)(\s*)$/D', $item, $placeholder) !== 1) {
                continue;
            }
            $type = $getColumnType(trim($table, '"'), $columns[$index]);
            if ($type === null) {
                continue;
            }
            $items[$index] = $placeholder[1] . 'CAST(' . $placeholder[2] . ' AS ' . $type . ')' . $placeholder[3];
            $changed = true;
        }

        return $changed ? $head . implode(',', $items) . ($from === null ? '' : substr($rest, $from)) : $sql;
    }
}

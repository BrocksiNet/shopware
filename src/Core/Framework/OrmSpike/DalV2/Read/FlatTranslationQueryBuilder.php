<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\DalV2\Read;

use Shopware\Core\Framework\DataAbstractionLayer\Dbal\QueryBuilder;
use Shopware\Core\Framework\Log\Package;

/**
 * Lever R1a (`shape=join`): a query builder that joins the translation tables directly instead of as derived tables.
 *
 * The DAL joins `(SELECT first.fk, first.name AS "x.translation.name", o1.name AS "x.translation.override_1.name" FROM
 * t first LEFT JOIN t o1 ON ... WHERE first.language_id = :sys) alias ON alias.fk = root.id`.
 * This builder emits `LEFT JOIN t first ON first.fk = root.id AND first.language_id = :sys LEFT JOIN t o1 ON ...` and
 * rewrites the references to the derived columns (single identifiers like `x.translation.name`) to the qualified columns.
 * Selects that were a bare derived column keep their output name through an `AS` alias, because the hydrator reads them by name.
 *
 * @internal
 */
#[Package('framework')]
class FlatTranslationQueryBuilder extends QueryBuilder
{
    /**
     * @var array<string, array{fromAlias: string, queryBuilder: QueryBuilder, joinCondition: string}>
     */
    private array $flatJoins = [];

    public function addTranslationJoin(string $fromAlias, string $joinAlias, QueryBuilder $queryBuilder, string $joinCondition): void
    {
        $this->flatJoins[$joinAlias] = ['fromAlias' => $fromAlias, 'queryBuilder' => $queryBuilder, 'joinCondition' => $joinCondition];
    }

    public function getTranslationQueryBuilder(string $joinAlias): ?QueryBuilder
    {
        return $this->flatJoins[$joinAlias]['queryBuilder'] ?? null;
    }

    public function getSQL(): string
    {
        if ($this->flatJoins === []) {
            return parent::getSQL();
        }

        $query = clone $this;
        /** @var array<string, string> $map */
        $map = [];

        foreach ($this->flatJoins as $joinAlias => $join) {
            $inner = $join['queryBuilder'];
            $innerSql = $inner->getSQL();
            $from = strpos($innerSql, ' FROM ');
            $where = strrpos($innerSql, ' WHERE ');
            if ($from === false || $where === false) {
                throw new \LogicException('Unexpected translation query: ' . $innerSql);
            }
            $joins = explode(' LEFT JOIN ', substr($innerSql, $from + 6, $where - $from - 6));
            [$table, $firstAlias] = explode(' ', array_shift($joins), 2);
            $firstCondition = str_replace($joinAlias, $firstAlias, $join['joinCondition']) . ' AND ' . substr($innerSql, $where + 7);
            $query->leftJoin($join['fromAlias'], $table, $firstAlias, $firstCondition);
            foreach ($joins as $override) {
                [$overrideTable, $rest] = explode(' ', $override, 2);
                [$overrideAlias, $condition] = explode(' ON ', $rest, 2);
                $query->leftJoin($firstAlias, $overrideTable, $overrideAlias, $condition);
            }

            foreach ($inner->getSelectParts() as $part) {
                if (preg_match('/^(.+) as ([`"])(.+)\2$/s', $part, $m) === 1) {
                    $map[$m[2] . $m[3] . $m[2]] = $m[1];
                }
            }
        }

        $placeholders = [];
        $parts = [];
        foreach ($this->getSelectParts() as $part) {
            $trimmed = trim($part);
            if (isset($map[$trimmed])) {
                $placeholder = '@@T' . \count($placeholders) . '@@';
                $placeholders[$placeholder] = $trimmed;
                $parts[] = $map[$trimmed] . ' as ' . $placeholder;

                continue;
            }
            $parts[] = $part;
        }
        $query->select(...$parts);
        // the clone has no translation joins left, so the parent builds the plain SQL (and the title comment)
        $query->flatJoins = [];

        return strtr(strtr($query->getSQL(), $map), $placeholders);
    }
}

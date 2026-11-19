<?php declare(strict_types=1);

namespace Shopware\Core\Content\Product\SearchKeyword;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Adapter\Database\Dialect\SqlDialect;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\QueryBuilder;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Uuid\Uuid;

#[Package('inventory')]
class KeywordLoader
{
    /**
     * @internal
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly SqlDialect $dialect,
    ) {
    }

    /**
     * @param array<array{normal: list<string>, reversed: list<string>}> $tokenSlops
     *
     * @return list<list<string>>
     */
    public function fetch(array $tokenSlops, Context $context): array
    {
        $query = new QueryBuilder($this->connection);
        $query->select('keyword');

        // with a fence the dictionary rows are lowered once in a derived table, otherwise the expression is evaluated for every pattern of every row
        $fenced = $this->dialect->subqueryFence() !== '';
        $keywordSql = $fenced ? 'd.k' : $this->dialect->caseInsensitive('keyword');
        $reversedSql = $fenced ? 'd.r' : $this->dialect->caseInsensitive('reversed');

        if ($fenced) {
            $query->from(
                '(SELECT keyword, ' . $this->dialect->caseInsensitive('keyword') . ' AS k, ' . $this->dialect->caseInsensitive('reversed') . ' AS r'
                . ' FROM product_keyword_dictionary WHERE language_id = :language' . $this->dialect->subqueryFence() . ')',
                'd'
            );
        } else {
            $query->from('product_keyword_dictionary');
        }

        $query->setTitle('search::detect-keywords');

        $counter = 0;
        $wheres = [];
        $index = 0;

        foreach ($tokenSlops as $slops) {
            $slopsWheres = [];
            foreach ($slops['normal'] as $slop) {
                ++$counter;
                $slopsWheres[] = $keywordSql . ' LIKE :reg' . $counter;
                $query->setParameter('reg' . $counter, mb_strtolower($slop));
            }
            foreach ($slops['reversed'] as $slop) {
                ++$counter;
                $slopsWheres[] = $reversedSql . ' LIKE :reg' . $counter;
                $query->setParameter('reg' . $counter, mb_strtolower($slop));
            }
            $query->addSelect('IF (' . implode(' OR ', $slopsWheres) . ', 1, 0) as \'' . $index++ . '\'');
            $wheres = array_merge($wheres, $slopsWheres);
        }

        if (!$fenced) {
            $query->andWhere('language_id = :language');
        }
        $query->andWhere('(' . implode(' OR ', $wheres) . ')');
        $query->addOrderBy('keyword', 'ASC');

        $query->setParameter('language', Uuid::fromHexToBytes($context->getLanguageId()));

        return $query->executeQuery()->fetchAllNumeric();
    }
}

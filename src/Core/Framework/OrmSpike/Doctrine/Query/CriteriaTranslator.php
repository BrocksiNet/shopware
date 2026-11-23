<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\Doctrine\Query;

use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\QueryBuilder;
use Shopware\Core\Framework\Adapter\Database\Dialect\SqlDialect;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\OrmSpike\Harness\ScenarioNotSupported;

/**
 * Store API criteria JSON to a DQL query. Fields come from an allow list that maps the public field name to a DQL expression,
 * values are always bound parameters, so user input never reaches the DQL string (only allow listed expressions and generated aliases do).
 *
 * @internal
 */
#[Package('framework')]
final class CriteriaTranslator
{
    private const RANGE = ['gte' => '>=', 'gt' => '>', 'lte' => '<=', 'lt' => '<'];

    public function __construct(private readonly string $nameExpression)
    {
    }

    /**
     * @param array<string, mixed> $criteria
     */
    public function apply(QueryBuilder $qb, array $criteria, SqlDialect $dialect): void
    {
        $fields = [
            'active' => 'COALESCE(p.active, pp.active)',
            'stock' => 'p.stock',
            'name' => $this->nameExpression,
            'productNumber' => 'p.productNumber',
        ];

        foreach ($criteria['filter'] ?? [] as $i => $filter) {
            $field = $fields[$filter['field'] ?? ''] ?? throw new \InvalidArgumentException('Field not allowed: ' . ($filter['field'] ?? ''));
            $key = 'f' . $i;
            switch ($filter['type'] ?? '') {
                case 'equals':
                    $qb->andWhere($field . ' = :' . $key)->setParameter($key, \is_bool($filter['value']) ? (int) $filter['value'] : $filter['value']);

                    break;
                case 'contains':
                    $qb->andWhere('SW_CI(' . $field . ') LIKE :' . $key)->setParameter($key, $dialect->caseInsensitiveValue('%' . $filter['value'] . '%'), ParameterType::STRING);

                    break;
                case 'range':
                    foreach (self::RANGE as $name => $operator) {
                        if (isset($filter['parameters'][$name])) {
                            $qb->andWhere($field . ' ' . $operator . ' :' . $key . $name)->setParameter($key . $name, (int) $filter['parameters'][$name], ParameterType::INTEGER);
                        }
                    }

                    break;
                default:
                    throw new ScenarioNotSupported('Filter type not supported by the DQL translator: ' . ($filter['type'] ?? ''));
            }
        }

        $qb->resetDQLPart('orderBy');
        foreach ($criteria['sort'] ?? [] as $i => $sort) {
            $field = $fields[$sort['field'] ?? ''] ?? throw new \InvalidArgumentException('Field not allowed: ' . ($sort['field'] ?? ''));
            $direction = strtoupper((string) ($sort['order'] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';
            if (str_contains($field, '(')) {
                // DQL can only order by a path or a result variable
                $qb->addSelect($field . ' AS HIDDEN s' . $i);
                $field = 's' . $i;
            }
            $qb->addOrderBy($field, $direction);
        }
    }
}

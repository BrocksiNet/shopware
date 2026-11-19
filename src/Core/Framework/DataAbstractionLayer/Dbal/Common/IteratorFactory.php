<?php declare(strict_types=1);

namespace Shopware\Core\Framework\DataAbstractionLayer\Dbal\Common;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Adapter\Database\Dialect\IdBinding;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\EntityDefinitionQueryHelper;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\QueryBuilder;
use Shopware\Core\Framework\DataAbstractionLayer\DefinitionInstanceRegistry;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\Log\Package;

/**
 * @final
 */
#[Package('framework')]
class IteratorFactory
{
    /**
     * @internal
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly DefinitionInstanceRegistry $registry
    ) {
    }

    /**
     * @param array{offset: int|null}|null $lastId
     */
    public function createIterator(string|EntityDefinition $definition, ?array $lastId = null, int $limit = 50, ?string $versionId = null): IterableQuery
    {
        if (\is_string($definition)) {
            $definition = $this->registry->getByEntityName($definition);
        }

        $entity = $definition->getEntityName();

        $escaped = EntityDefinitionQueryHelper::escape($entity);
        $query = new QueryBuilder($this->connection);
        $query->from($escaped);
        $query->setMaxResults($limit);

        if ($definition->isVersionAware() && $versionId !== null) {
            $query->andWhere($escaped . '.version_id = :versionId');
            $query->setParameter('versionId', IdBinding::value($versionId));
        }

        if ($definition->hasAutoIncrement()) {
            $query->select($escaped . '.auto_increment', IdBinding::text($escaped . '.id') . ' as id');
            $query->andWhere($escaped . '.auto_increment > :lastId');
            $query->addOrderBy($escaped . '.auto_increment');
            $query->setParameter('lastId', 0);

            if ($lastId !== null) {
                $query->setParameter('lastId', $lastId['offset']);
            }

            return new LastIdQuery($query);
        }

        $query->select($escaped . '.id', IdBinding::text($escaped . '.id'));
        $query->setFirstResult(0);
        if ($lastId !== null) {
            $query->setFirstResult((int) $lastId['offset']);
        }

        return new OffsetQuery($query);
    }
}

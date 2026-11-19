<?php declare(strict_types=1);

namespace Shopware\Core\Content\Product\Aggregate\ProductSearchKeyword;

use Shopware\Core\Framework\Adapter\Database\Dialect\IdBinding;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\EntityHydrator;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\Log\Package;

#[Package('inventory')]
class ProductSearchKeywordHydrator extends EntityHydrator
{
    protected function assign(EntityDefinition $definition, Entity $entity, string $root, array $row, Context $context): Entity
    {
        if (isset($row[$root . '.id'])) {
            $entity->id = IdBinding::toHex($row[$root . '.id']);
        }
        if (isset($row[$root . '.versionId'])) {
            $entity->versionId = IdBinding::toHex($row[$root . '.versionId']);
        }
        if (isset($row[$root . '.languageId'])) {
            $entity->languageId = IdBinding::toHex($row[$root . '.languageId']);
        }
        if (isset($row[$root . '.productId'])) {
            $entity->productId = IdBinding::toHex($row[$root . '.productId']);
        }
        if (isset($row[$root . '.keyword'])) {
            $entity->keyword = $row[$root . '.keyword'];
        }
        if (isset($row[$root . '.ranking'])) {
            $entity->ranking = (float) $row[$root . '.ranking'];
        }
        if (isset($row[$root . '.createdAt'])) {
            $entity->createdAt = new \DateTimeImmutable($row[$root . '.createdAt']);
        }
        if (isset($row[$root . '.updatedAt'])) {
            $entity->updatedAt = new \DateTimeImmutable($row[$root . '.updatedAt']);
        }
        $entity->product = $this->manyToOne($row, $root, $definition->getField('product'), $context);
        $entity->language = $this->manyToOne($row, $root, $definition->getField('language'), $context);

        $this->translate($definition, $entity, $row, $root, $context, $definition->getTranslatedFields());
        $this->hydrateFields($definition, $entity, $root, $row, $context, $definition->getExtensionFields());

        return $entity;
    }
}

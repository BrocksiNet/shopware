<?php declare(strict_types=1);

namespace Shopware\Core\Content\Product\Aggregate\ProductPrice;

use Shopware\Core\Framework\Adapter\Database\Dialect\IdBinding;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\EntityHydrator;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\Log\Package;

#[Package('inventory')]
class ProductPriceHydrator extends EntityHydrator
{
    protected function assign(EntityDefinition $definition, Entity $entity, string $root, array $row, Context $context): Entity
    {
        if (isset($row[$root . '.id'])) {
            $entity->id = IdBinding::toHex($row[$root . '.id']);
        }
        if (isset($row[$root . '.versionId'])) {
            $entity->versionId = IdBinding::toHex($row[$root . '.versionId']);
        }
        if (isset($row[$root . '.productId'])) {
            $entity->productId = IdBinding::toHex($row[$root . '.productId']);
        }
        if (isset($row[$root . '.ruleId'])) {
            $entity->ruleId = IdBinding::toHex($row[$root . '.ruleId']);
        }
        if (\array_key_exists($root . '.price', $row)) {
            $entity->price = $definition->decode('price', self::value($row, $root, 'price'));
        }
        if (isset($row[$root . '.quantityStart'])) {
            $entity->quantityStart = (int) $row[$root . '.quantityStart'];
        }
        if (isset($row[$root . '.quantityEnd'])) {
            $entity->quantityEnd = (int) $row[$root . '.quantityEnd'];
        }
        if (\array_key_exists($root . '.customFields', $row)) {
            $entity->customFields = $definition->decode('customFields', self::value($row, $root, 'customFields'));
        }
        if (isset($row[$root . '.createdAt'])) {
            $entity->createdAt = new \DateTimeImmutable($row[$root . '.createdAt']);
        }
        if (isset($row[$root . '.updatedAt'])) {
            $entity->updatedAt = new \DateTimeImmutable($row[$root . '.updatedAt']);
        }
        $entity->product = $this->manyToOne($row, $root, $definition->getField('product'), $context);
        $entity->rule = $this->manyToOne($row, $root, $definition->getField('rule'), $context);

        $this->translate($definition, $entity, $row, $root, $context, $definition->getTranslatedFields());
        $this->hydrateFields($definition, $entity, $root, $row, $context, $definition->getExtensionFields());
        $this->customFields($definition, $row, $root, $entity, $definition->getField('customFields'), $context);

        return $entity;
    }
}

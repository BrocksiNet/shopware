<?php declare(strict_types=1);

namespace Shopware\Core\Content\Product\Aggregate\ProductReview;

use Shopware\Core\Framework\Adapter\Database\Dialect\IdBinding;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\EntityHydrator;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\Log\Package;

#[Package('after-sales')]
class ProductReviewHydrator extends EntityHydrator
{
    protected function assign(EntityDefinition $definition, Entity $entity, string $root, array $row, Context $context): Entity
    {
        if (isset($row[$root . '.id'])) {
            $entity->id = IdBinding::toHex($row[$root . '.id']);
        }
        if (isset($row[$root . '.productId'])) {
            $entity->productId = IdBinding::toHex($row[$root . '.productId']);
        }
        if (isset($row[$root . '.customerId'])) {
            $entity->customerId = IdBinding::toHex($row[$root . '.customerId']);
        }
        if (isset($row[$root . '.salesChannelId'])) {
            $entity->salesChannelId = IdBinding::toHex($row[$root . '.salesChannelId']);
        }
        if (isset($row[$root . '.languageId'])) {
            $entity->languageId = IdBinding::toHex($row[$root . '.languageId']);
        }
        if (isset($row[$root . '.externalUser'])) {
            $entity->externalUser = $row[$root . '.externalUser'];
        }
        if (isset($row[$root . '.externalEmail'])) {
            $entity->externalEmail = $row[$root . '.externalEmail'];
        }
        if (isset($row[$root . '.title'])) {
            $entity->title = $row[$root . '.title'];
        }
        if (\array_key_exists($root . '.content', $row)) {
            $entity->content = $definition->decode('content', self::value($row, $root, 'content'));
        }
        if (isset($row[$root . '.points'])) {
            $entity->points = (float) $row[$root . '.points'];
        }
        if (isset($row[$root . '.status'])) {
            $entity->status = (bool) $row[$root . '.status'];
        }
        if (\array_key_exists($root . '.comment', $row)) {
            $entity->comment = $definition->decode('comment', self::value($row, $root, 'comment'));
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
        $entity->customer = $this->manyToOne($row, $root, $definition->getField('customer'), $context);
        $entity->salesChannel = $this->manyToOne($row, $root, $definition->getField('salesChannel'), $context);
        $entity->language = $this->manyToOne($row, $root, $definition->getField('language'), $context);

        $this->translate($definition, $entity, $row, $root, $context, $definition->getTranslatedFields());
        $this->hydrateFields($definition, $entity, $root, $row, $context, $definition->getExtensionFields());
        $this->customFields($definition, $row, $root, $entity, $definition->getField('customFields'), $context);

        return $entity;
    }
}

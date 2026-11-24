<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Projector;

use Doctrine\DBAL\Connection;
use Shopware\Core\Content\Media\MediaEvents;
use Shopware\Core\Content\Product\Events\ProductIndexerEvent;
use Shopware\Core\Content\Product\Events\ProductStockAlteredEvent;
use Shopware\Core\Content\Product\ProductEvents;
use Shopware\Core\Content\Property\PropertyEvents;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Adapter\Database\Dialect\IdBinding;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityDeletedEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\Log\Package;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * The projector signals:
 * - {@see ProductIndexerEvent}: after the product indexer wrote the derived columns (cheapest price, category tree, keywords, ...).
 *   When a variant is written, `ProductIndexer::update()` emits `[variant, parent]` without the other sibling variants whose inherited
 *   `cheapest_price_accessor` may have changed, so we expand any parent ids in the event to their children.
 * - {@see ProductStockAlteredEvent}: stock changes of orders (`StockStorage::alter()`), which do not run the indexer.
 * - `product.deleted`: the ids of deleted products.
 * - Low-cardinality referenced entities embedded in the document: manufacturer, property/option, and media writes/translations.
 *   Catalog-wide entities (`tax`, `currency`) are intentionally NOT expanded inline (they touch up to 100% of products and belong in a queued rebuild).
 *
 * @internal
 */
#[Package('framework')]
final readonly class ReadStoreProjectorSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private ReadStoreProjector $projector,
        private ?Connection $connection = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ProductIndexerEvent::class => 'onIndexed',
            ProductStockAlteredEvent::class => 'onStockAltered',
            ProductEvents::PRODUCT_DELETED_EVENT => 'onDeleted',
            ProductEvents::PRODUCT_MANUFACTURER_WRITTEN_EVENT => 'onManufacturerWritten',
            ProductEvents::PRODUCT_MANUFACTURER_TRANSLATION_WRITTEN_EVENT => 'onManufacturerTranslationWritten',
            PropertyEvents::PROPERTY_GROUP_OPTION_WRITTEN_EVENT => 'onPropertyOptionWritten',
            PropertyEvents::PROPERTY_GROUP_OPTION_TRANSLATION_WRITTEN_EVENT => 'onPropertyOptionTranslationWritten',
            MediaEvents::MEDIA_WRITTEN_EVENT => 'onMediaWritten',
            MediaEvents::MEDIA_TRANSLATION_WRITTEN_EVENT => 'onMediaTranslationWritten',
        ];
    }

    public function onIndexed(ProductIndexerEvent $event): void
    {
        if (!$this->projector->enabled()) {
            return;
        }

        $ids = array_values($event->getIds());
        if ($ids !== [] && $this->connection !== null) {
            // Sibling variants inherit `cheapest_price_accessor` from their parent's variant group;
            // `ProductIndexer::update()` emits `[variant, parent]` on a variant write, omitting the other siblings.
            $children = $this->fetchHexIds(
                'SELECT ' . IdBinding::text('id') . ' FROM product WHERE parent_id IN (:ids) AND version_id = :version',
                $ids,
            );
            if ($children !== []) {
                $ids = array_values(array_unique([...$ids, ...$children]));
            }
        }

        $this->projector->schedule($ids);
    }

    public function onStockAltered(ProductStockAlteredEvent $event): void
    {
        $this->projector->schedule(array_values($event->getIds()));
    }

    public function onDeleted(EntityDeletedEvent $event): void
    {
        $ids = [];
        foreach ($event->getIds() as $id) {
            if (\is_string($id)) {
                $ids[] = $id;
            }
        }
        $this->projector->remove($ids);
    }

    public function onManufacturerWritten(EntityWrittenEvent $event): void
    {
        $this->projectByManufacturer(self::extractIds($event));
    }

    public function onManufacturerTranslationWritten(EntityWrittenEvent $event): void
    {
        $this->projectByManufacturer(self::extractIds($event, 'productManufacturerId'));
    }

    public function onPropertyOptionWritten(EntityWrittenEvent $event): void
    {
        $this->projectByPropertyOption(self::extractIds($event));
    }

    public function onPropertyOptionTranslationWritten(EntityWrittenEvent $event): void
    {
        $this->projectByPropertyOption(self::extractIds($event, 'propertyGroupOptionId'));
    }

    public function onMediaWritten(EntityWrittenEvent $event): void
    {
        $this->projectByMedia(self::extractIds($event));
    }

    public function onMediaTranslationWritten(EntityWrittenEvent $event): void
    {
        $this->projectByMedia(self::extractIds($event, 'mediaId'));
    }

    /**
     * @param list<string> $manufacturerIds
     */
    private function projectByManufacturer(array $manufacturerIds): void
    {
        if (!$this->projector->enabled() || $this->connection === null || $manufacturerIds === []) {
            return;
        }

        $productIds = $this->fetchHexIds(
            'SELECT ' . IdBinding::text('id') . ' FROM product WHERE product_manufacturer_id IN (:ids) AND version_id = :version',
            $manufacturerIds,
        );
        if ($productIds !== []) {
            $children = $this->fetchHexIds(
                'SELECT ' . IdBinding::text('id') . ' FROM product WHERE parent_id IN (:ids) AND version_id = :version',
                $productIds,
            );
            $productIds = array_values(array_unique([...$productIds, ...$children]));
        }
        $this->projector->schedule($productIds);
    }

    /**
     * @param list<string> $optionIds
     */
    private function projectByPropertyOption(array $optionIds): void
    {
        if (!$this->projector->enabled() || $this->connection === null || $optionIds === []) {
            return;
        }

        $fromProps = $this->fetchHexIds(
            'SELECT DISTINCT ' . IdBinding::text('product_id') . ' FROM product_property WHERE property_group_option_id IN (:ids) AND product_version_id = :version',
            $optionIds,
        );
        $fromOpts = $this->fetchHexIds(
            'SELECT DISTINCT ' . IdBinding::text('product_id') . ' FROM product_option WHERE property_group_option_id IN (:ids) AND product_version_id = :version',
            $optionIds,
        );

        $this->projector->schedule(array_values(array_unique([...$fromProps, ...$fromOpts])));
    }

    /**
     * @param list<string> $mediaIds
     */
    private function projectByMedia(array $mediaIds): void
    {
        if (!$this->projector->enabled() || $this->connection === null || $mediaIds === []) {
            return;
        }

        $productIds = $this->fetchHexIds(
            'SELECT DISTINCT ' . IdBinding::text('product_id') . ' FROM product_media WHERE media_id IN (:ids) AND product_version_id = :version',
            $mediaIds,
        );
        $this->projector->schedule($productIds);
    }

    /**
     * @param list<string> $hexIds
     *
     * @return list<string>
     */
    private function fetchHexIds(string $sql, array $hexIds): array
    {
        if ($this->connection === null || $hexIds === []) {
            return [];
        }

        $rows = $this->connection->fetchFirstColumn(
            $sql,
            [
                'ids' => IdBinding::values($hexIds),
                'version' => IdBinding::value(Defaults::LIVE_VERSION),
            ],
            [
                'ids' => IdBinding::listType(),
            ],
        );

        $result = [];
        foreach ($rows as $row) {
            if (\is_string($row) && $row !== '') {
                $result[] = strtolower($row);
            }
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private static function extractIds(EntityWrittenEvent $event, ?string $compositeKey = null): array
    {
        $ids = [];
        foreach ($event->getIds() as $id) {
            if (\is_string($id)) {
                $ids[] = $id;
            } elseif ($compositeKey !== null && \is_array($id) && isset($id[$compositeKey]) && \is_string($id[$compositeKey])) {
                $ids[] = $id[$compositeKey];
            }
        }

        return array_values(array_unique($ids));
    }
}

<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Projector;

use Shopware\Core\Content\Product\Events\ProductIndexerEvent;
use Shopware\Core\Content\Product\Events\ProductStockAlteredEvent;
use Shopware\Core\Content\Product\ProductEvents;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityDeletedEvent;
use Shopware\Core\Framework\Log\Package;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * The projector signals:
 * - {@see ProductIndexerEvent}: after the product indexer wrote the derived columns (cheapest price, category tree, keywords, ...).
 *   Its ids already include the children of a written parent (`ProductIndexer::update()`), so children are not looked up again.
 * - {@see ProductStockAlteredEvent}: stock changes of orders, which do not run the indexer.
 * - `product.deleted`: the ids of deleted products.
 * Changes of related entities alone (a manufacturer rename, a property group rename) send none of these, the same gap as the Elasticsearch `ProductUpdater`.
 *
 * @internal
 */
#[Package('framework')]
final readonly class ReadStoreProjectorSubscriber implements EventSubscriberInterface
{
    public function __construct(private ReadStoreProjector $projector)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ProductIndexerEvent::class => 'onIndexed',
            ProductStockAlteredEvent::class => 'onStockAltered',
            ProductEvents::PRODUCT_DELETED_EVENT => 'onDeleted',
        ];
    }

    public function onIndexed(ProductIndexerEvent $event): void
    {
        $this->projector->schedule(array_values($event->getIds()));
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
}

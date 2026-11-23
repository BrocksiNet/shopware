<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\Fair;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityWriteResult;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\Price;
use Shopware\Core\Framework\DataAbstractionLayer\Write\EntityExistence;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\OrmSpike\Harness\Params;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * The `EntityWrittenContainerEvent` the DAL dispatches for the S4 `create` and the stock `update`, built by hand for the
 * full variants of the ORMs and of DBAL. Entity names, operations, primary keys, existence and payload keys follow the events
 * captured from `product.repository` (`orm-spike:fair-check --impl=dal`); the payload carries the defaults the DAL resolves
 * (version ids, `isCloseout`, `purchaseSteps`, ...) although the full variants do not write those columns.
 * Dispatched like in `EntityRepository`: system scope with the `SYSTEM_SCOPE_DAL_WRITE_EVENT` state.
 *
 * @internal
 */
#[Package('framework')]
final class WrittenEvents
{
    public function __construct(private readonly EventDispatcherInterface $dispatcher)
    {
    }

    /**
     * @param list<WrittenProduct> $products
     */
    public function created(array $products, Params $params, Context $context): void
    {
        $live = $params->liveVersionId;
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $results = ['product' => [], 'product_translation' => [], 'product_visibility' => [], 'product_category' => [], 'category' => []];
        $categories = [];

        foreach ($products as $p) {
            $results['product'][] = new EntityWriteResult($p->id, [
                'id' => $p->id,
                'versionId' => $live,
                'parentVersionId' => $live,
                'productManufacturerVersionId' => $live,
                'taxId' => $p->taxId,
                'productMediaVersionId' => $live,
                'canonicalProductVersionId' => $live,
                'cmsPageVersionId' => $live,
                'price' => [new Price($p->currencyId, $p->net, $p->gross, true)],
                'productNumber' => $p->productNumber,
                'restockTime' => null,
                'active' => $p->active,
                'isCloseout' => false,
                'stock' => $p->stock,
                'purchaseSteps' => 1,
                'minPurchase' => 1,
                'shippingFree' => false,
                'markAsTopseller' => false,
                'type' => 'physical',
                'createdAt' => $now,
                'availableStock' => $p->stock,
            ], 'product', EntityWriteResult::OPERATION_INSERT, new EntityExistence('product', ['id' => $p->id, 'version_id' => $live], false, false, false, []));

            foreach ($p->translations as $t) {
                $results['product_translation'][] = new EntityWriteResult(
                    ['productId' => $p->id, 'languageId' => $t['languageId']],
                    ['productId' => $p->id, 'languageId' => $t['languageId'], 'productVersionId' => $live, 'name' => $t['name'], 'createdAt' => $now],
                    'product_translation',
                    EntityWriteResult::OPERATION_INSERT,
                    new EntityExistence('product_translation', ['product_id' => $p->id, 'language_id' => $t['languageId'], 'product_version_id' => $live], false, false, false, [])
                );
            }
            foreach ($p->visibilities as $v) {
                $results['product_visibility'][] = new EntityWriteResult(
                    $v['id'],
                    ['id' => $v['id'], 'productId' => $p->id, 'productVersionId' => $live, 'salesChannelId' => $v['salesChannelId'], 'visibility' => $v['visibility'], 'createdAt' => $now],
                    'product_visibility',
                    EntityWriteResult::OPERATION_INSERT,
                    new EntityExistence('product_visibility', ['id' => $v['id']], false, false, false, [])
                );
            }
            foreach ($p->categoryIds as $categoryId) {
                $results['product_category'][] = new EntityWriteResult(
                    ['productId' => $p->id, 'categoryId' => $categoryId],
                    ['productId' => $p->id, 'productVersionId' => $live, 'categoryId' => $categoryId, 'categoryVersionId' => $live],
                    'product_category',
                    EntityWriteResult::OPERATION_INSERT,
                    new EntityExistence('product_category', [], false, false, false, [])
                );
                $categories[$categoryId] = true;
            }
        }
        // the many-to-many mapping reports every referenced category once, without payload
        foreach (array_keys($categories) as $categoryId) {
            $results['category'][] = new EntityWriteResult((string) $categoryId, [], 'category', EntityWriteResult::OPERATION_UPDATE);
        }

        $this->dispatch($results, $context);
    }

    /**
     * @param list<WrittenProduct> $products
     */
    public function stockUpdated(array $products, Params $params, Context $context): void
    {
        $live = $params->liveVersionId;
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $results = ['product' => [], 'product_translation' => []];

        foreach ($products as $p) {
            $results['product'][] = new EntityWriteResult($p->id, [
                'versionId' => $live,
                'parentVersionId' => $live,
                'productManufacturerVersionId' => $live,
                'productMediaVersionId' => $live,
                'canonicalProductVersionId' => $live,
                'cmsPageVersionId' => $live,
                'stock' => $p->newStock,
                'updatedAt' => $now,
                'availableStock' => $p->newStock,
                'id' => $p->id,
            ], 'product', EntityWriteResult::OPERATION_UPDATE, new EntityExistence('product', ['id' => $p->id, 'version_id' => $live], true, false, false, ['exists' => '1', 'parent_id' => null]));

            // the DAL touches the translation of the context language when a product is updated
            $languageId = $params->systemLanguageId;
            $results['product_translation'][] = new EntityWriteResult(
                ['productId' => $p->id, 'languageId' => $languageId],
                ['productId' => $p->id, 'languageId' => $languageId, 'productVersionId' => $live, 'updatedAt' => $now],
                'product_translation',
                EntityWriteResult::OPERATION_UPDATE,
                new EntityExistence('product_translation', ['product_id' => $p->id, 'language_id' => $languageId, 'product_version_id' => $live], true, false, false, ['exists' => '1'])
            );
        }

        $this->dispatch($results, $context);
    }

    /**
     * @param array<string, list<EntityWriteResult>> $results
     */
    private function dispatch(array $results, Context $context): void
    {
        $event = EntityWrittenContainerEvent::createWithWrittenEvents($results, $context, []);
        $context->scope(Context::SYSTEM_SCOPE, fn () => $this->dispatcher->dispatch($event), [Context::SYSTEM_SCOPE_DAL_WRITE_EVENT]);
    }
}

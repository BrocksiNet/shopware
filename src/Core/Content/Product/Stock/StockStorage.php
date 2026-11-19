<?php declare(strict_types=1);

namespace Shopware\Core\Content\Product\Stock;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Shopware\Core\Content\Product\Events\ProductBackInStockEvent;
use Shopware\Core\Content\Product\Events\ProductNoLongerAvailableEvent;
use Shopware\Core\Content\Product\Events\ProductStockAlteredEvent;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Adapter\Database\Dialect\IdBinding;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Doctrine\RetryableQuery;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[Package('inventory')]
class StockStorage extends AbstractStockStorage
{
    /**
     * @internal
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly EventDispatcherInterface $dispatcher
    ) {
    }

    public function getDecorated(): AbstractStockStorage
    {
        throw new DecorationPatternException(self::class);
    }

    public function load(StockLoadRequest $stockRequest, SalesChannelContext $context): StockDataCollection
    {
        return new StockDataCollection([]);
    }

    /**
     * @param list<StockAlteration> $changes
     */
    public function alter(array $changes, Context $context): void
    {
        if ($context->getVersionId() !== Defaults::LIVE_VERSION) {
            return;
        }

        if ($changes === []) {
            return;
        }

        $sql = <<<'SQL'
            UPDATE product
            SET stock = stock + :quantity, sales = sales - :quantity, available_stock = stock, updated_at = NOW()
            WHERE id = :id AND version_id = :version
        SQL;

        $query = new RetryableQuery(
            $this->connection,
            $this->connection->prepare($sql)
        );

        foreach ($changes as $alteration) {
            $query->execute([
                'quantity' => $alteration->quantityDelta(),
                'id' => IdBinding::value($alteration->productId),
                'version' => IdBinding::value(Defaults::LIVE_VERSION),
            ]);
        }

        $this->updateAvailableFlag(array_column($changes, 'productId'), $context);

        $this->dispatcher->dispatch(new ProductStockAlteredEvent(array_column($changes, 'productId'), $context));
    }

    /**
     * @param list<string> $productIds
     */
    public function index(array $productIds, Context $context): void
    {
        if ($context->getVersionId() !== Defaults::LIVE_VERSION) {
            return;
        }

        $this->updateAvailableFlag($productIds, $context);
    }

    /**
     * @param list<string> $ids
     */
    private function updateAvailableFlag(array $ids, Context $context): void
    {
        $ids = array_filter(array_unique($ids));

        if ($ids === []) {
            return;
        }

        $bytes = IdBinding::values($ids);

        $sql = '
            UPDATE product
            LEFT JOIN product parent
                ON parent.id = product.parent_id
                AND parent.version_id = product.version_id

            SET product.available = IFNULL((
                COALESCE(product.is_closeout, parent.is_closeout, 0) * product.stock
                >=
                COALESCE(product.is_closeout, parent.is_closeout, 0) * IFNULL(product.min_purchase, parent.min_purchase)
            ), 0),
                product.updated_at = NOW()
            WHERE product.id IN (:ids)
            AND product.version_id = :version
        ';

        if ($this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            // PostgreSQL has no UPDATE ... LEFT JOIN, the parent is read in a correlated sub select
            $sql = '
                UPDATE product
                SET available = COALESCE((
                    SELECT COALESCE(product.is_closeout, parent.is_closeout, false)::int * product.stock
                        >= COALESCE(product.is_closeout, parent.is_closeout, false)::int * COALESCE(product.min_purchase, parent.min_purchase)
                    FROM (SELECT 1) one
                    LEFT JOIN product parent
                        ON parent.id = product.parent_id
                        AND parent.version_id = product.version_id
                ), false),
                    updated_at = NOW()
                WHERE product.id IN (:ids)
                AND product.version_id = :version
            ';
        }

        $before = $this->connection->fetchAllKeyValue(
            'SELECT ' . IdBinding::text('id') . ', available FROM product WHERE id IN (:ids) AND product.version_id = :version',
            ['ids' => $bytes, 'version' => IdBinding::value($context->getVersionId())],
            ['ids' => IdBinding::listType()]
        );

        RetryableQuery::retryable($this->connection, function () use ($sql, $context, $bytes): void {
            $this->connection->executeStatement(
                $sql,
                ['ids' => $bytes, 'version' => IdBinding::value($context->getVersionId())],
                ['ids' => IdBinding::listType()]
            );
        });

        $after = $this->connection->fetchAllKeyValue(
            'SELECT ' . IdBinding::text('id') . ', available FROM product WHERE id IN (:ids) AND product.version_id = :version',
            ['ids' => $bytes, 'version' => IdBinding::value($context->getVersionId())],
            ['ids' => IdBinding::listType()]
        );

        $noLongerAvailable = [];
        $backInStock = [];
        foreach ($before as $id => $available) {
            if ((bool) $available === (bool) $after[$id]) {
                continue;
            }

            if ((bool) $available) {
                $noLongerAvailable[] = (string) $id;

                continue;
            }

            $backInStock[] = (string) $id;
        }

        if ($noLongerAvailable !== []) {
            $this->dispatcher->dispatch(new ProductNoLongerAvailableEvent($noLongerAvailable, $context));
        }

        if ($backInStock !== []) {
            $this->dispatcher->dispatch(new ProductBackInStockEvent($backInStock, $context));
        }
    }
}

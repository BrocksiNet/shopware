<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Projector;

use Shopware\Core\Framework\Log\Package;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Keeps the configured stores current: builds the documents of written products and stores them, removes the documents of deleted products.
 *
 * Modes (`shopware.read_store.projector.mode`, env `READ_STORE_PROJECTOR_MODE`):
 * - `sync`: in the request, right after the product indexer (the indexer runs in the request too unless indexing is queued);
 * - `queue`: one {@see ProjectProductsMessage} per call on the `async` transport, with a deduplication id from its sorted ids (only a transport that implements deduplication uses it).
 * The stores are a comma-separated list of store keys (env `READ_STORE_PROJECTOR_STORES`), empty switches the projector off.
 *
 * @internal
 */
#[Package('framework')]
class ReadStoreProjector
{
    public const MODE_SYNC = 'sync';
    public const MODE_QUEUE = 'queue';

    /**
     * @var list<string>
     */
    private readonly array $stores;

    public function __construct(
        private readonly StorageRegistry $registry,
        private readonly MessageBusInterface $bus,
        string $stores,
        private readonly string $builder,
        private readonly string $mode,
    ) {
        $this->stores = array_values(array_filter(array_map(trim(...), explode(',', $stores))));
    }

    public function enabled(): bool
    {
        return $this->stores !== [];
    }

    /**
     * Projects the products now (`sync`) or through the queue (`queue`).
     *
     * @param list<string> $ids
     */
    public function schedule(array $ids): void
    {
        $ids = self::normalize($ids);
        if ($ids === [] || !$this->enabled()) {
            return;
        }

        if ($this->mode === self::MODE_QUEUE) {
            $this->bus->dispatch(new ProjectProductsMessage($ids));

            return;
        }

        $this->project($ids);
    }

    /**
     * Builds the documents of the products and stores them in every configured store; ids without a product row are removed.
     *
     * @param list<string> $ids
     */
    public function project(array $ids): void
    {
        $ids = self::normalize($ids);
        if ($ids === [] || !$this->enabled()) {
            return;
        }

        $documents = $this->registry->builder($this->builder)->build($ids);
        $missing = array_values(array_diff($ids, array_keys($documents)));

        foreach ($this->stores as $key) {
            $storage = $this->registry->storage($key);
            if ($documents !== []) {
                $storage->store(array_values($documents));
            }
            if ($missing !== []) {
                $storage->remove($missing);
            }
        }
    }

    /**
     * Removes the documents of deleted products, always right away: the delete event carries the ids, nothing has to be read.
     *
     * @param list<string> $ids
     */
    public function remove(array $ids): void
    {
        $ids = self::normalize($ids);
        if ($ids === [] || !$this->enabled()) {
            return;
        }

        foreach ($this->stores as $key) {
            $this->registry->storage($key)->remove($ids);
        }
    }

    /**
     * @param list<mixed> $ids
     *
     * @return list<string>
     */
    private static function normalize(array $ids): array
    {
        $normalized = [];
        foreach ($ids as $id) {
            if (\is_string($id) && $id !== '') {
                $normalized[strtolower($id)] = true;
            }
        }

        return array_keys($normalized);
    }
}

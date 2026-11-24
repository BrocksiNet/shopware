<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Projector;

use Psr\Clock\ClockInterface;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\ReadStore\Builder\DocumentBuilder;
use Shopware\Core\Framework\ReadStore\Builder\ProductIds;
use Shopware\Core\Framework\ReadStore\Port\Storage;
use Symfony\Component\Clock\NativeClock;

/**
 * Blue/green rebuild of one store: `setup()` a new generation, fill it batch by batch from the write database, `swap()` it live (which drops the old one).
 * Readers see the old generation until the swap. Writes the projector makes during the rebuild go to the live and the pending generation
 * ({@see Storage::store()} without generation), so a product written while its batch was already built is not lost.
 *
 * @internal
 */
#[Package('framework')]
final readonly class Rebuilder
{
    public function __construct(
        private ProductIds $productIds,
        private ClockInterface $clock = new NativeClock(),
    ) {
    }

    /**
     * @param (callable(int $documents): void)|null $progress called after every batch with the number of documents stored so far
     *
     * @return array{generation: string, documents: int, products: int}
     */
    public function rebuild(Storage $storage, DocumentBuilder $builder, int $batchSize = 500, ?callable $progress = null): array
    {
        $generation = $this->generation();
        $builder->reset();
        $storage->setup($generation);

        $documents = 0;
        foreach ($this->productIds->batches($batchSize) as $ids) {
            $built = $builder->build($ids);
            if ($built !== []) {
                $storage->store(array_values($built), $generation);
            }
            $documents += \count($built);
            if ($progress !== null) {
                $progress($documents);
            }
        }

        $storage->swap($generation);

        return ['generation' => $generation, 'documents' => $documents, 'products' => $this->productIds->count()];
    }

    /**
     * `g` and the UTC time to the microsecond: lower case letters and digits only (an index or table name in every engine), sortable.
     */
    public function generation(): string
    {
        return 'g' . $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('YmdHisu');
    }
}

<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Projector;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\MessageQueue\AsyncMessageInterface;
use Shopware\Core\Framework\MessageQueue\DeduplicatableMessageInterface;
use Shopware\Core\Framework\Util\Hasher;

/**
 * Queue mode of the {@see ReadStoreProjector}: the products to project.
 * The deduplication id is the hash of the sorted ids. Core has no middleware that reads it (the interface is experimental and leaves it to the transport),
 * so with the Doctrine transport two identical messages are both handled; projecting twice is idempotent, only the work doubles.
 *
 * @internal
 */
#[Package('framework')]
final readonly class ProjectProductsMessage implements AsyncMessageInterface, DeduplicatableMessageInterface
{
    /**
     * @param list<string> $ids
     */
    public function __construct(public array $ids)
    {
    }

    public function deduplicationId(): string
    {
        $ids = $this->ids;
        sort($ids);

        return Hasher::hash('read-store-project:' . implode(',', $ids));
    }
}

<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Port\Result;

use Shopware\Core\Framework\Log\Package;

/**
 * @internal
 */
#[Package('framework')]
final readonly class StoreResult
{
    /**
     * @param list<array<string, mixed>> $documents the page of hits, full documents in hit order
     * @param int|null $total `null` unless `Total::EXACT` was requested; with a collapse the number of groups
     * @param array<string, AggregationResult> $aggregations
     * @param array<string, float> $scores search score per hit id ({@see \Shopware\Core\Framework\ReadStore\Port\SearchAware} only)
     */
    public function __construct(
        public array $documents,
        public ?int $total = null,
        public array $aggregations = [],
        public array $scores = [],
    ) {
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_map(static fn (array $document): string => (string) $document['id'], $this->documents);
    }
}

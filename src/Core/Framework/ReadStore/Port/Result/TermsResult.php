<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Port\Result;

use Shopware\Core\Framework\Log\Package;

/**
 * @internal
 */
#[Package('framework')]
final readonly class TermsResult extends AggregationResult
{
    /**
     * @param list<array{key: string|int|float|bool|null, count: int}> $buckets
     */
    public function __construct(
        string $name,
        public array $buckets,
    ) {
        parent::__construct($name);
    }

    /**
     * @return array<string, int> non-null keys only
     */
    public function counts(): array
    {
        $counts = [];
        foreach ($this->buckets as $bucket) {
            if ($bucket['key'] !== null) {
                $counts[(string) $bucket['key']] = $bucket['count'];
            }
        }

        return $counts;
    }
}

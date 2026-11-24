<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Port\Result;

use Shopware\Core\Framework\Log\Package;

/**
 * Result of min, max, avg, sum and count; `null` when no document had a value (count is never null).
 *
 * @internal
 */
#[Package('framework')]
final readonly class MetricResult extends AggregationResult
{
    public function __construct(
        string $name,
        public int|float|string|null $value,
    ) {
        parent::__construct($name);
    }
}

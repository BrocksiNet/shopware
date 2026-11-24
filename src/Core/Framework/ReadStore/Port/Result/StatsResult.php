<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Port\Result;

use Shopware\Core\Framework\Log\Package;

/**
 * @internal
 */
#[Package('framework')]
final readonly class StatsResult extends AggregationResult
{
    public function __construct(
        string $name,
        public int|float|string|null $min,
        public int|float|string|null $max,
        public ?float $avg,
        public int|float|null $sum,
    ) {
        parent::__construct($name);
    }
}

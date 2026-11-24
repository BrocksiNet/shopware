<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Port\Result;

use Shopware\Core\Framework\Log\Package;

/**
 * @internal
 */
#[Package('framework')]
abstract readonly class AggregationResult
{
    public function __construct(public string $name)
    {
    }
}

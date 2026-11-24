<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Port\Criteria\Aggregation;

use Shopware\Core\Framework\Log\Package;

/**
 * Number of distinct non-missing values (`COUNT(DISTINCT field)`, like the DAL `CountAggregation`); on a list field the distinct elements.
 *
 * @internal
 */
#[Package('framework')]
final readonly class Count extends Aggregation
{
}

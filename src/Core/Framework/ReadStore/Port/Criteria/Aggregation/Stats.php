<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Port\Criteria\Aggregation;

use Shopware\Core\Framework\Log\Package;

/**
 * min, max, avg and sum in one aggregation (the listing price filter uses min and max of `cheapestPrice`).
 *
 * @internal
 */
#[Package('framework')]
final readonly class Stats extends Aggregation
{
}

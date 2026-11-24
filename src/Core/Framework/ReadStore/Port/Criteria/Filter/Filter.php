<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Port\Criteria\Filter;

use Shopware\Core\Framework\Log\Package;

/**
 * Fields are logical document fields (`name`, `categoryTree`, `cheapestPrice`, `visibilities`, `customFields.x`, ...),
 * {@see \Shopware\Core\Framework\ReadStore\Port\Schema\FieldResolver} maps them to document paths for the context.
 *
 * @internal
 */
#[Package('framework')]
interface Filter
{
}

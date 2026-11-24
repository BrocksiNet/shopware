<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Port\Criteria\Filter;

use Shopware\Core\Framework\Log\Package;

/**
 * Case-insensitive prefix match (SQL `LIKE 'value%'`).
 *
 * @internal
 */
#[Package('framework')]
final readonly class Prefix implements Filter
{
    public function __construct(
        public string $field,
        public string $value,
    ) {
    }
}

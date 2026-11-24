<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Port\Criteria\Filter;

use Shopware\Core\Framework\Log\Package;

/**
 * A `null` in the values matches a missing value. On a list field: the list contains one of the values. An empty list matches nothing.
 *
 * @internal
 */
#[Package('framework')]
final readonly class EqualsAny implements Filter
{
    /**
     * @param list<string|int|float|bool|null> $values
     */
    public function __construct(
        public string $field,
        public array $values,
    ) {
    }
}

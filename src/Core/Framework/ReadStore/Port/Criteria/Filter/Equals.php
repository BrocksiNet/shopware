<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Port\Criteria\Filter;

use Shopware\Core\Framework\Log\Package;

/**
 * `null` matches a missing value. On a list field: the list contains the value.
 *
 * @internal
 */
#[Package('framework')]
final readonly class Equals implements Filter
{
    public function __construct(
        public string $field,
        public string|int|float|bool|null $value,
    ) {
    }
}

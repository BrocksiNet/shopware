<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Port\Schema;

use Shopware\Core\Framework\Log\Package;

/**
 * Declares a document property, like `#[Field]` of the prototype. `queryable: false` fields are payload only (hydration), filters,
 * sortings and aggregations on them throw {@see \Shopware\Core\Framework\ReadStore\Port\NotSupportedByEngine}.
 *
 * @internal
 */
#[Package('framework')]
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class Field
{
    public function __construct(
        public FieldType $type,
        public FieldScope $scope = FieldScope::NONE,
        public bool $queryable = true,
    ) {
    }
}

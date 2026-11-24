<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Port\Criteria\Filter;

use Shopware\Core\Framework\Log\Package;

/**
 * Bounds are numbers or date time strings in the document format (`Y-m-d H:i:s.v`), `null` means unbounded.
 *
 * @internal
 */
#[Package('framework')]
final readonly class Range implements Filter
{
    public function __construct(
        public string $field,
        public int|float|string|null $gte = null,
        public int|float|string|null $lte = null,
        public int|float|string|null $gt = null,
        public int|float|string|null $lt = null,
    ) {
    }
}

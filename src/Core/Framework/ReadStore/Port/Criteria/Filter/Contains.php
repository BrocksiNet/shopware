<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Port\Criteria\Filter;

use Shopware\Core\Framework\Log\Package;

/**
 * Case-insensitive substring match (SQL `LIKE '%value%'`), not a word search.
 *
 * @internal
 */
#[Package('framework')]
final readonly class Contains implements Filter
{
    public function __construct(
        public string $field,
        public string $value,
    ) {
    }
}

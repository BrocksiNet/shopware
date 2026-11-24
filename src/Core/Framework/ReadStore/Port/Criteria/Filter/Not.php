<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Port\Criteria\Filter;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\ReadStore\ReadStoreException;

/**
 * `NOT (f1 <operator> f2 ...)`, three-valued like SQL: an unknown inner result stays unknown and matches nothing.
 *
 * @internal
 */
#[Package('framework')]
final readonly class Not implements Filter
{
    /**
     * @param list<Filter> $filters
     */
    public function __construct(
        public string $operator,
        public array $filters,
    ) {
        if (!\in_array($operator, [Multi::AND, Multi::OR], true)) {
            throw ReadStoreException::invalidCriteria('unknown operator ' . $operator);
        }
    }
}

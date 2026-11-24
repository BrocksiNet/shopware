<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Port\Criteria\Filter;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\ReadStore\ReadStoreException;

/**
 * @internal
 */
#[Package('framework')]
final readonly class Multi implements Filter
{
    public const AND = 'AND';
    public const OR = 'OR';

    /**
     * @param list<Filter> $filters
     */
    public function __construct(
        public string $operator,
        public array $filters,
    ) {
        if (!\in_array($operator, [self::AND, self::OR], true)) {
            throw ReadStoreException::invalidCriteria('unknown operator ' . $operator);
        }
    }
}

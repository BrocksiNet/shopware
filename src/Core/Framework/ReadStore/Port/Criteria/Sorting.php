<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Port\Criteria;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\ReadStore\ReadStoreException;

/**
 * Missing values sort first ascending and last descending (MySQL). Strings sort case- and accent-insensitive (`utf8mb4_unicode_ci`).
 * The field `_score` sorts by the search score of {@see \Shopware\Core\Framework\ReadStore\Port\SearchAware}.
 *
 * @internal
 */
#[Package('framework')]
final readonly class Sorting
{
    public const ASC = 'ASC';
    public const DESC = 'DESC';
    public const SCORE = '_score';

    public function __construct(
        public string $field,
        public string $direction = self::ASC,
    ) {
        if (!\in_array($direction, [self::ASC, self::DESC], true)) {
            throw ReadStoreException::invalidCriteria('unknown direction ' . $direction);
        }
    }
}

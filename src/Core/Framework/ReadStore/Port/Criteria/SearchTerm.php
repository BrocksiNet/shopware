<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Port\Criteria;

use Shopware\Core\Framework\Log\Package;

/**
 * A storefront search term, resolved by Shopware's `ProductSearchTermInterpreter` before it reaches the store.
 *
 * - `scoredKeywords`: every keyword with its score (`getTerms()` of the search pattern, duplicates kept, each scores on its own);
 * - `original`/`originalScore`: the whole term, a keyword equal to it matches (or-logic) and a keyword containing it scores;
 * - `tokenKeywords`: per token of the term, the dictionary keywords it matches (`getTokenTerms()`);
 * - `andLogic` (`product_search_config.and_logic`): a document needs one keyword of every token group,
 *   otherwise one keyword equal to the original term or to any scored keyword (`getAllTerms()`).
 *
 * DAL score (`ProductSearchBuilder`): per keyword row of the product, `score * ranking` for every equal keyword and
 * `originalScore * ranking` when the keyword contains the original term, summed over the rows.
 *
 * @internal
 */
#[Package('framework')]
final readonly class SearchTerm
{
    /**
     * @param list<array{keyword: string, score: float}> $scoredKeywords
     * @param list<list<string>> $tokenKeywords
     */
    public function __construct(
        public string $original,
        public array $scoredKeywords,
        public array $tokenKeywords,
        public float $originalScore = 1.0,
        public bool $andLogic = false,
    ) {
    }

    /**
     * @return list<string>
     */
    public function anyKeywords(): array
    {
        return array_values(array_unique([$this->original, ...array_column($this->scoredKeywords, 'keyword')]));
    }
}

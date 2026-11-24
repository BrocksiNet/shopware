<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Framework\ReadStore\Contract;

use Shopware\Core\Framework\ReadStore\Port\Criteria\Filter\Range;
use Shopware\Core\Framework\ReadStore\Port\Criteria\SearchTerm;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Sorting;
use Shopware\Core\Framework\ReadStore\Port\Criteria\StoreCriteria;
use Shopware\Core\Framework\ReadStore\Port\Total;
use Shopware\Tests\Unit\Core\Framework\ReadStore\Contract\ContractFixtures as F;

/**
 * Contract of {@see \Shopware\Core\Framework\ReadStore\Port\SearchAware}: which documents match a search term (the keywords of the context
 * language, or- and and-logic, case and accent folded) and that a higher ranked keyword scores higher. Absolute score values are not part
 * of the contract, an engine may score with its own formula as long as the order of these cases holds.
 *
 * @internal
 *
 * @phpstan-require-extends ContractTestCase
 */
trait SearchContract
{
    public function testOrLogicMatchesAnyKeywordOfTheContextLanguage(): void
    {
        $criteria = $this->searchCriteria('hemd', [['hemd']]);

        static::assertSame([F::S1, F::S4], $this->search($criteria)->ids());
        static::assertSame([], $this->search($criteria, F::context(language: F::EN))->ids());
    }

    public function testOrLogicNeedsOneEqualKeyword(): void
    {
        // S3 has "apfelchen", not "apfel"
        static::assertSame([F::S1, F::S4], $this->search($this->searchCriteria('apfel hemd', [['apfel'], ['hemd']]))->ids());
    }

    public function testAndLogicNeedsAKeywordOfEveryToken(): void
    {
        static::assertSame([F::S1], $this->search($this->searchCriteria('apfel hemd', [['apfel'], ['hemd']], andLogic: true))->ids());

        $oneToken = $this->searchCriteria('apfel', [['apfel', 'apfelchen']], andLogic: true);
        $oneToken->sortings = [new Sorting('productNumber')];
        static::assertSame([F::S1, F::S3], $this->search($oneToken)->ids());
    }

    public function testKeywordsMatchIgnoringCaseAndAccents(): void
    {
        static::assertSame([F::S3], $this->search($this->searchCriteria('Äpfelchen', [['Äpfelchen']]))->ids());
    }

    public function testHigherRankedKeywordsScoreHigher(): void
    {
        // shirt: S1 and S4 rank it 700, S2 350
        $result = $this->search($this->searchCriteria('shirt', [['shirt']]), F::context(language: F::EN));

        static::assertSame([F::S1, F::S4, F::S2], $result->ids());
        static::assertSame([F::S1, F::S4, F::S2], array_keys($result->scores));
        static::assertGreaterThan($result->scores[F::S2], $result->scores[F::S4]);
    }

    public function testSearchWithFilterPagingAndTotal(): void
    {
        $criteria = $this->searchCriteria('hemd', [['hemd']]);
        $criteria->filters = [new Range('price', gte: 12)];
        static::assertSame([F::S4], $this->search($criteria)->ids());

        $paged = $this->searchCriteria('hemd', [['hemd']]);
        $paged->limit = 1;
        $paged->total = Total::EXACT;
        $result = $this->search($paged);
        static::assertSame([F::S1], $result->ids());
        static::assertSame(2, $result->total);
    }

    public function testSearchWithoutMatch(): void
    {
        $criteria = $this->searchCriteria('nothing', [['nothing']]);
        $criteria->total = Total::EXACT;
        $result = $this->search($criteria);

        static::assertSame([], $result->ids());
        static::assertSame(0, $result->total);
    }

    /**
     * Like `ProductSearchTermInterpreter` builds it: every token keyword scores 1, sorted by score, ties by id.
     *
     * @param list<list<string>> $tokenKeywords
     */
    private function searchCriteria(string $term, array $tokenKeywords, bool $andLogic = false): StoreCriteria
    {
        $scored = [];
        foreach ($tokenKeywords as $group) {
            foreach ($group as $keyword) {
                $scored[] = ['keyword' => $keyword, 'score' => 1.0];
            }
        }

        return new StoreCriteria(
            sortings: [new Sorting(Sorting::SCORE, Sorting::DESC)],
            search: new SearchTerm($term, $scored, $tokenKeywords, andLogic: $andLogic),
        );
    }
}

<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Framework\ReadStore\Contract;

use Shopware\Core\Framework\ReadStore\Port\Criteria\Filter\Contains;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Filter\Equals;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Filter\EqualsAny;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Filter\Multi;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Filter\Not;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Filter\Prefix;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Filter\Range;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Sorting;
use Shopware\Core\Framework\ReadStore\Port\Criteria\StoreCriteria;
use Shopware\Core\Framework\ReadStore\Port\FilterAware;
use Shopware\Core\Framework\ReadStore\Port\NotSupportedByEngine;
use Shopware\Core\Framework\ReadStore\Port\StoreContext;
use Shopware\Core\Framework\ReadStore\Port\Total;
use Shopware\Tests\Unit\Core\Framework\ReadStore\Contract\ContractFixtures as F;

/**
 * Contract of {@see \Shopware\Core\Framework\ReadStore\Port\FilterAware}, the semantics of the DAL on MySQL (see the port's docblock).
 * Without a context argument the queries run in German, sales channel 1, EUR, gross, no rules.
 *
 * @internal
 *
 * @phpstan-require-extends ContractTestCase
 */
trait FilterContract
{
    public function testIdsWithoutSortingKeepTheirOrder(): void
    {
        static::assertSame([F::S4, F::S1, F::S2], $this->ids(new StoreCriteria(ids: [F::S4, F::S1, 'ffffffffffffffffffffffffffffffff', F::S2])));
    }

    public function testEqualsOnAStringIgnoresCase(): void
    {
        static::assertSame([F::S1], $this->ids(new StoreCriteria(filters: [new Equals('productNumber', 'sw-s1')])));
        static::assertSame([F::S2], $this->ids(new StoreCriteria(filters: [new Equals('name', 'BANANA SHIRT')])));
    }

    public function testContainsOnATranslatedFieldIgnoresCaseAndAccents(): void
    {
        $criteria = new StoreCriteria(filters: [new Contains('name', 'apfel')], sortings: [new Sorting('productNumber')]);

        static::assertSame([F::S1, F::S3], $this->ids($criteria));
        static::assertSame([F::S3], $this->ids($criteria, F::context(language: F::EN)));
    }

    public function testPrefix(): void
    {
        $criteria = new StoreCriteria(filters: [new Prefix('productNumber', 'sw-p')], sortings: [new Sorting('productNumber')]);

        static::assertSame([F::P, F::V1, F::V2, F::V3], $this->ids($criteria));
    }

    public function testEqualsAndEqualsAnyOnListFields(): void
    {
        $sorted = [new Sorting('productNumber')];

        static::assertSame(
            [F::P, F::V1, F::V2, F::V3, F::S3],
            $this->ids(new StoreCriteria(filters: [new Equals('categoryTree', F::CAT_SHOES)], sortings: $sorted)),
        );
        static::assertSame(
            [F::V1, F::V2, F::S1, F::S2],
            $this->ids(new StoreCriteria(filters: [new EqualsAny('propertyIds', [F::PROP_RED, F::PROP_BLUE])], sortings: $sorted)),
        );
    }

    public function testVisibilityPerSalesChannel(): void
    {
        $criteria = new StoreCriteria(filters: [new Range('visibilities', gte: 30)], sortings: [new Sorting('productNumber')]);

        static::assertSame([F::P, F::V1, F::V2, F::V3, F::S1, F::S2], $this->ids($criteria));
        static::assertSame([F::P, F::V1, F::V2, F::V3, F::S3], $this->ids($criteria, F::context(salesChannel: F::SC2)));
    }

    public function testPricePerCurrencyAndTaxState(): void
    {
        $criteria = new StoreCriteria(filters: [new Range('price', gte: 15, lte: 30)], sortings: [new Sorting('productNumber')]);

        static::assertSame([F::S2, F::S3, F::S4], $this->ids($criteria));
        // USD: S1 has an own price of 12, the others the EUR price times 1.1
        static::assertSame([F::S2, F::S4], $this->ids($criteria, F::context(currency: F::USD)));

        $net = new StoreCriteria(filters: [new Range('price', lte: 13)], sortings: [new Sorting('productNumber')]);
        static::assertSame([F::S1, F::S4], $this->ids($net, F::context(gross: false)));
        static::assertSame([F::S1], $this->ids(new StoreCriteria(filters: [new Range('price.gross', lte: 13)], sortings: [new Sorting('productNumber')]), F::context(gross: false)));
    }

    public function testCheapestPriceUsesTheFirstRuleWithAPriceThenDefault(): void
    {
        $criteria = new StoreCriteria(filters: [new Range('cheapestPrice', lte: 38)], sortings: [new Sorting('productNumber')]);

        static::assertSame([F::S1, F::S2, F::S3, F::S4], $this->ids($criteria));
        static::assertSame([F::V1, F::S1, F::S2, F::S3, F::S4], $this->ids($criteria, F::context(ruleIds: ['ffffffffffffffffffffffffffffffff', F::RULE])));
        // USD: V1's rule price is 35 * 1.1 = 38.5
        static::assertSame([F::S1, F::S2, F::S3, F::S4], $this->ids($criteria, F::context(currency: F::USD, ruleIds: [F::RULE])));
    }

    public function testSortByCheapestPriceWithRule(): void
    {
        $criteria = new StoreCriteria(filters: [new Equals('categoryTree', F::CAT_SHOES)], sortings: [new Sorting('cheapestPrice')]);

        static::assertSame([F::S3, F::V1, F::P, F::V3, F::V2], $this->ids($criteria, F::context(ruleIds: [F::RULE])));
    }

    public function testCheapestPriceIsNotAnsweredWhenTheContextRoundsDifferently(): void
    {
        $context = new StoreContext(F::DE, F::SC1, F::EUR, roundingInterval: 0.05);

        $this->expectException(NotSupportedByEngine::class);
        $this->storageFilter(new StoreCriteria(filters: [new Range('cheapestPrice', lte: 38)]), $context);
    }

    public function testPriceIsNotAnsweredWhenTheContextRoundsDifferently(): void
    {
        $context = new StoreContext(F::DE, F::SC1, F::EUR, roundingDecimals: 3);

        $this->expectException(NotSupportedByEngine::class);
        $this->storageFilter(new StoreCriteria(sortings: [new Sorting('price')]), $context);
    }

    public function testEqualsNullAndNotEqualsAreNullSafe(): void
    {
        static::assertSame([F::S3], $this->ids(new StoreCriteria(filters: [new Equals('manufacturerId', null)])));

        $notA = new StoreCriteria(filters: [new Not(Multi::AND, [new Equals('manufacturerId', F::MAN_A)])], sortings: [new Sorting('productNumber')]);
        static::assertSame([F::V2, F::S2, F::S3, F::S4], $this->ids($notA));

        $anyWithNull = new StoreCriteria(filters: [new EqualsAny('manufacturerId', [F::MAN_C, null])], sortings: [new Sorting('productNumber')]);
        static::assertSame([F::V2, F::S3], $this->ids($anyWithNull));
    }

    public function testNotOfAnUnknownComparisonIsUnknown(): void
    {
        // S3 has no rating: NOT (rating >= 4) is unknown, not true
        $criteria = new StoreCriteria(filters: [new Not(Multi::AND, [new Range('ratingAverage', gte: 4)])]);
        static::assertSame([F::S2], $this->ids($criteria));

        // no product has a description: NOT (description LIKE '%x%') is unknown for all
        static::assertSame([], $this->ids(new StoreCriteria(filters: [new Not(Multi::AND, [new Contains('description', 'x')])])));
    }

    public function testMultiOr(): void
    {
        $criteria = new StoreCriteria(
            filters: [new Multi(Multi::OR, [new Equals('manufacturerId', F::MAN_B), new Range('price', lte: 10)])],
            sortings: [new Sorting('productNumber')],
        );

        static::assertSame([F::S1, F::S2, F::S4], $this->ids($criteria));
    }

    public function testBoolDateTimeAndCustomFields(): void
    {
        $sorted = [new Sorting('productNumber')];

        static::assertSame([F::S1, F::S4], $this->ids(new StoreCriteria(filters: [new Equals('shippingFree', true)], sortings: $sorted)));
        static::assertSame([F::V3], $this->ids(new StoreCriteria(filters: [new Equals('active', false)], sortings: $sorted)));
        static::assertSame([F::S2], $this->ids(new StoreCriteria(filters: [new Range('releaseDate', gte: '2026-02-01 00:00:00.000')], sortings: $sorted)));
        static::assertSame([F::S1], $this->ids(new StoreCriteria(filters: [new Equals('customFields.swag_color', 'rot')])));
        static::assertSame([F::S1], $this->ids(new StoreCriteria(filters: [new Equals('customFields.swag_color', 'RED')]), F::context(language: F::EN)));
    }

    public function testSortByTranslatedNameWithPagingAndTotal(): void
    {
        $criteria = new StoreCriteria(
            filters: [new Equals('active', true)],
            sortings: [new Sorting('name')],
            limit: 3,
            offset: 1,
            total: Total::EXACT,
        );

        // Apfel Hemd, Äpfelchen Schuh, banana shirt, Dattel Hemd, Kirsch Turnschuh (P, V1, V2)
        $result = $this->filter($criteria);
        static::assertSame([F::S3, F::S2, F::S4], $result->ids());
        static::assertSame(7, $result->total);
    }

    public function testSortDescendingAndByTwoFields(): void
    {
        static::assertSame(
            [F::S2, F::S4, F::S1],
            $this->ids(new StoreCriteria(filters: [new Equals('categoryTree', F::CAT_SHIRTS)], sortings: [new Sorting('price', Sorting::DESC)])),
        );
        static::assertSame(
            [F::S3, F::V2, F::V1, F::P],
            $this->ids(new StoreCriteria(
                filters: [new Equals('categoryTree', F::CAT_SHOES), new Equals('active', true)],
                sortings: [new Sorting('name'), new Sorting('productNumber', Sorting::DESC)],
            )),
        );
    }

    public function testMissingValuesSortFirstAscendingAndLastDescending(): void
    {
        $filters = [new Equals('categoryTree', F::CAT_SHOES)];

        static::assertSame([F::S3, F::P, F::V1, F::V2, F::V3], $this->ids(new StoreCriteria(filters: $filters, sortings: [new Sorting('ratingAverage')])));
        static::assertSame([F::P, F::V1, F::V2, F::V3, F::S3], $this->ids(new StoreCriteria(filters: $filters, sortings: [new Sorting('ratingAverage', Sorting::DESC)])));
    }

    public function testPostFiltersRestrictHitsAndTotal(): void
    {
        $result = $this->filter(new StoreCriteria(
            filters: [new Equals('categoryTree', F::CAT_SHIRTS)],
            postFilters: [new Equals('manufacturerId', F::MAN_B)],
            sortings: [new Sorting('productNumber')],
            total: Total::EXACT,
        ));

        static::assertSame([F::S2, F::S4], $result->ids());
        static::assertSame(2, $result->total);
    }

    public function testCollapseOrdersGroupsByMinimumAscending(): void
    {
        // S3 30 | null group: P 40 | G_P: V1 40, V2 45; G_P and the null group tie at 40, the representative id decides
        $result = $this->filter($this->collapseCriteria(new Sorting('price')));

        static::assertSame([F::S3, F::P, F::V1], $result->ids());
        static::assertSame(3, $result->total);
    }

    public function testCollapseOrdersGroupsByMaximumDescending(): void
    {
        $result = $this->filter($this->collapseCriteria(new Sorting('price', Sorting::DESC)));

        static::assertSame([F::V2, F::P, F::S3], $result->ids());
        static::assertSame(3, $result->total);
    }

    public function testCollapseByCheapestPriceAlwaysUsesTheMinimum(): void
    {
        // G_P: MIN 40 (not MAX 45) ties with the null group at 40, its representative under DESC is V2
        static::assertSame([F::P, F::V2, F::S3], $this->ids($this->collapseCriteria(new Sorting('cheapestPrice', Sorting::DESC))));
    }

    public function testCollapsePaging(): void
    {
        $criteria = $this->collapseCriteria(new Sorting('price'));
        $criteria->limit = 1;
        $criteria->offset = 1;

        $result = $this->filter($criteria);
        static::assertSame([F::P], $result->ids());
        static::assertSame(3, $result->total);
    }

    public function testEmptyResult(): void
    {
        $result = $this->filter(new StoreCriteria(filters: [new Equals('productNumber', 'nope')], total: Total::EXACT));

        static::assertSame([], $result->ids());
        static::assertSame(0, $result->total);
    }

    public function testNestedAssociationFieldsAreNotQueryable(): void
    {
        $this->expectException(NotSupportedByEngine::class);
        $this->storageFilter(new StoreCriteria(filters: [new Equals('manufacturer.name', 'x')]));
    }

    private function collapseCriteria(Sorting $sorting): StoreCriteria
    {
        return new StoreCriteria(
            filters: [new Equals('categoryTree', F::CAT_SHOES), new Equals('active', true)],
            sortings: [$sorting],
            groupField: 'displayGroup',
            total: Total::EXACT,
        );
    }

    /**
     * Without {@see ContractTestCase::attempt()}, for tests that expect {@see NotSupportedByEngine}.
     */
    private function storageFilter(StoreCriteria $criteria, ?StoreContext $context = null): void
    {
        $storage = $this->storage;
        static::assertInstanceOf(FilterAware::class, $storage);
        $storage->filter($criteria, $context ?? F::context());
    }
}

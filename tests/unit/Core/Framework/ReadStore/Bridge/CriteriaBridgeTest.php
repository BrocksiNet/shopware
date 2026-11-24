<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Framework\ReadStore\Bridge;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Product\Aggregate\ProductVisibility\ProductVisibilityDefinition;
use Shopware\Core\Content\Product\SalesChannel\ProductAvailableFilter;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\CashRoundingConfig;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Bucket\FilterAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Bucket\TermsAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\EntityAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\MinAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\ContainsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Grouping\FieldGrouping;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Query\ScoreQuery;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\ReadStore\Bridge\BridgedCriteria;
use Shopware\Core\Framework\ReadStore\Bridge\CriteriaBridge;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Aggregation\Min;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Aggregation\Terms;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Filter\Equals;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Filter\Multi;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Filter\Range;
use Shopware\Core\Framework\ReadStore\Port\NotSupportedByEngine;
use Shopware\Core\Framework\ReadStore\Port\Schema\FieldResolver;
use Shopware\Core\Framework\ReadStore\Port\Total;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;

/**
 * @internal
 */
#[CoversClass(CriteriaBridge::class)]
#[CoversClass(BridgedCriteria::class)]
class CriteriaBridgeTest extends TestCase
{
    private const SC = '5c15c15c15c15c15c15c15c15c15c100';

    private CriteriaBridge $bridge;

    protected function setUp(): void
    {
        $this->bridge = new CriteriaBridge(new FieldResolver(), 2);
    }

    public function testAddsImplicitProductAvailableFilterAndMapsAssociationPaths(): void
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('product.categoriesRo.id', 'ca7000000000000000000000000000a0'));
        $criteria->addPostFilter(new EqualsAnyFilter('properties.id', ['ed000000000000000000000000000001']));
        $criteria->addGroupField(new FieldGrouping('displayGroup'));
        $criteria->addSorting(new FieldSorting('name', FieldSorting::ASCENDING));
        $criteria->setLimit(24)->setOffset(24)->setTotalCountMode(Criteria::TOTAL_COUNT_MODE_EXACT);
        $criteria->addAggregation(new EntityAggregation('manufacturer', 'manufacturerId', 'product_manufacturer'));
        $criteria->addAggregation(new FilterAggregation('f', new MinAggregation('minPrice', 'price.gross'), [new EqualsFilter('shippingFree', true)]));

        $bridged = $this->bridge->convert($criteria, $this->salesChannelContext(['r1', 'r2', 'r3']));

        static::assertSame(['r1', 'r2'], $bridged->context->ruleIds);
        static::assertCount(2, $bridged->criteria->filters);
        static::assertInstanceOf(Equals::class, $bridged->criteria->filters[0]);
        static::assertSame('categoryTree', $bridged->criteria->filters[0]->field);
        static::assertInstanceOf(Multi::class, $bridged->criteria->filters[1]);
        static::assertInstanceOf(Range::class, $bridged->criteria->filters[1]->filters[0]);
        static::assertSame(ProductVisibilityDefinition::VISIBILITY_LINK, $bridged->criteria->filters[1]->filters[0]->gte);
        static::assertSame('displayGroup', $bridged->criteria->groupField);
        static::assertSame(24, $bridged->criteria->limit);
        static::assertSame(24, $bridged->criteria->offset);
        static::assertSame(Total::EXACT, $bridged->criteria->total);
        static::assertSame(['manufacturer' => 'product_manufacturer'], $bridged->entityAggregations);
        static::assertInstanceOf(Terms::class, $bridged->criteria->aggregations[0]);
        static::assertInstanceOf(Min::class, $bridged->criteria->aggregations[1]);
        static::assertCount(1, $bridged->criteria->aggregations[1]->filters);
    }

    public function testConvertsKeywordSearchQueriesAndFilters(): void
    {
        $criteria = new Criteria();
        $criteria->addFilter(new ProductAvailableFilter(self::SC, ProductVisibilityDefinition::VISIBILITY_SEARCH));
        $criteria->addFilter(new MultiFilter(MultiFilter::CONNECTION_AND, [
            new EqualsAnyFilter('product.searchKeywords.keyword', ['hotel', 'hotel 00001']),
            new EqualsFilter('product.searchKeywords.languageId', Defaults::LANGUAGE_SYSTEM),
        ]));
        $criteria->addQuery(new ScoreQuery(new EqualsFilter('product.searchKeywords.keyword', 'hotel'), 100.0, 'product.searchKeywords.ranking'));
        $criteria->addQuery(new ScoreQuery(new ContainsFilter('product.searchKeywords.keyword', 'hotel'), 50.0, 'product.searchKeywords.ranking'));

        $bridged = $this->bridge->convert($criteria, $this->salesChannelContext());

        static::assertNotNull($bridged->criteria->search);
        static::assertSame('hotel', $bridged->criteria->search->original);
        static::assertSame(50.0, $bridged->criteria->search->originalScore);
        static::assertSame([['hotel', 'hotel 00001']], $bridged->criteria->search->tokenKeywords);
        static::assertSame([['keyword' => 'hotel', 'score' => 100.0]], $bridged->criteria->search->scoredKeywords);
        static::assertTrue($bridged->criteria->search->andLogic);
    }

    public function testRejectsRawCriteriaTermAndPayloadFieldsAndNextPagesTotal(): void
    {
        $context = $this->salesChannelContext();

        $termCriteria = new Criteria();
        $termCriteria->setTerm('Hotel');
        try {
            $this->bridge->convert($termCriteria, $context);
            static::fail('Expected NotSupportedByEngine for raw term');
        } catch (NotSupportedByEngine) {
        }

        $payloadCriteria = new Criteria();
        $payloadCriteria->addFilter(new ContainsFilter('manufacturer.name', 'Maker'));
        try {
            $this->bridge->convert($payloadCriteria, $context);
            static::fail('Expected NotSupportedByEngine for nested association field');
        } catch (NotSupportedByEngine) {
        }

        $nextPages = new Criteria();
        $nextPages->setTotalCountMode(Criteria::TOTAL_COUNT_MODE_NEXT_PAGES);
        try {
            $this->bridge->convert($nextPages, $context);
            static::fail('Expected NotSupportedByEngine for TOTAL_COUNT_MODE_NEXT_PAGES');
        } catch (NotSupportedByEngine) {
        }

        $sortedTerms = new Criteria();
        $sortedTerms->addAggregation(new TermsAggregation('t', 'manufacturerId', null, new FieldSorting('manufacturerId')));
        $this->expectException(NotSupportedByEngine::class);
        $this->bridge->convert($sortedTerms, $context);
    }

    /**
     * @param list<string> $ruleIds
     */
    private function salesChannelContext(array $ruleIds = []): SalesChannelContext
    {
        $salesChannel = new SalesChannelEntity();
        $salesChannel->setId(self::SC);
        $currency = new CurrencyEntity();
        $currency->setId(Defaults::CURRENCY);
        $currency->setItemRounding(new CashRoundingConfig(2, 0.01, true));

        $context = $this->createStub(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn(self::SC);
        $context->method('getSalesChannel')->willReturn($salesChannel);
        $context->method('getCurrencyId')->willReturn(Defaults::CURRENCY);
        $context->method('getCurrency')->willReturn($currency);
        $context->method('getLanguageId')->willReturn(Defaults::LANGUAGE_SYSTEM);
        $context->method('getRuleIds')->willReturn($ruleIds);
        $context->method('getTaxState')->willReturn('gross');
        $context->method('getItemRounding')->willReturn(new CashRoundingConfig(2, 0.01, true));
        $context->method('getContext')->willReturn(new Context(new SystemSource(), $ruleIds));

        return $context;
    }
}

<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Bridge;

use Shopware\Core\Content\Product\Aggregate\ProductVisibility\ProductVisibilityDefinition;
use Shopware\Core\Content\Product\SalesChannel\ProductAvailableFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Aggregation as DalAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Bucket\FilterAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Bucket\TermsAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\AvgAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\CountAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\EntityAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\MaxAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\MinAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\StatsAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\SumAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\ContainsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\Filter as DalFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\PrefixFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Query\ScoreQuery;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\CountSorting;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\ReadStore\Builder\Normalizer;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Aggregation\Aggregation;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Aggregation\Avg;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Aggregation\Count;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Aggregation\Max;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Aggregation\Min;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Aggregation\Stats;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Aggregation\Sum;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Aggregation\Terms;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Filter\Contains;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Filter\Equals;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Filter\EqualsAny;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Filter\Filter;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Filter\Multi;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Filter\Not;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Filter\Prefix;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Filter\Range;
use Shopware\Core\Framework\ReadStore\Port\Criteria\SearchTerm;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Sorting;
use Shopware\Core\Framework\ReadStore\Port\Criteria\StoreCriteria;
use Shopware\Core\Framework\ReadStore\Port\NotSupportedByEngine;
use Shopware\Core\Framework\ReadStore\Port\Schema\FieldResolver;
use Shopware\Core\Framework\ReadStore\Port\Schema\FieldType;
use Shopware\Core\Framework\ReadStore\Port\StoreContext;
use Shopware\Core\Framework\ReadStore\Port\Total;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Translates the DAL {@see Criteria} of the product routes (what they pass to `sales_channel.product.repository`) into {@see StoreCriteria}.
 * Anything it cannot translate exactly throws {@see NotSupportedByEngine} with the engine `bridge`, the caller then runs the whole query on the DAL.
 *
 * What it does on top of a field-by-field mapping:
 * - it adds the `ProductAvailableFilter` with `VISIBILITY_LINK` when the criteria has none, like `SalesChannelProductDefinition::processCriteria()`;
 * - it turns a `ProductAvailableFilter` into `visibilities >= v AND active = true`, the sales channel comes from the context
 *   (the {@see \Shopware\Core\Framework\ReadStore\Port\Schema\FieldScope::SALES_CHANNEL} key), so a filter for another sales channel is not supported;
 * - it recognises what `ProductSearchBuilder::build()` adds (score queries and keyword filters on `searchKeywords`) and turns it into one
 *   {@see SearchTerm}: the filters become the token groups (`andLogic`, every group must match, which is what the DAL filters do in both modes),
 *   the score queries the scored keywords and the original term;
 * - it maps the DAL association paths the routes use to the document lists (`categoriesRo.id` -> `categoryTree`, `properties.id` -> `propertyIds`, ...);
 * - it cuts the context rule ids to `shopware.dal.max_rule_prices`, like `CheapestPriceAccessorBuilder`.
 *
 * Associations, includes and fields of the criteria are ignored: a document always holds the whole product.
 *
 * @internal
 */
#[Package('framework')]
final readonly class CriteriaBridge
{
    private const ENGINE = 'bridge';

    /**
     * DAL path (without `product.`) => document field.
     */
    private const PATHS = [
        'categoriesRo.id' => 'categoryTree',
        'categories.id' => 'categoryIds',
        'streams.id' => 'streamIds',
        'tags.id' => 'tagIds',
        'properties.id' => 'propertyIds',
        'options.id' => 'optionIds',
        'manufacturer.id' => 'manufacturerId',
        'tax.id' => 'taxId',
        'unit.id' => 'unitId',
        'deliveryTime.id' => 'deliveryTimeId',
        'cover.id' => 'coverId',
    ];

    /**
     * Document fields that are no DAL field of the same meaning.
     */
    private const NOT_DAL = ['visibilities', 'searchKeywords', 'priceGross', 'priceNet', 'extensions', 'schemaVersion'];

    private const KEYWORD = 'searchKeywords.keyword';
    private const KEYWORD_LANGUAGE = 'searchKeywords.languageId';
    private const KEYWORD_RANKING = 'searchKeywords.ranking';

    public function __construct(
        private FieldResolver $resolver,
        private int $maxRulePrices = 100,
    ) {
    }

    public function convert(Criteria $criteria, SalesChannelContext $salesChannelContext): BridgedCriteria
    {
        $context = StoreContext::fromSalesChannelContext($salesChannelContext);
        if (\count($context->ruleIds) > $this->maxRulePrices) {
            $context = new StoreContext(
                $context->languageId,
                $context->salesChannelId,
                $context->currencyId,
                \array_slice($context->ruleIds, 0, $this->maxRulePrices),
                $context->gross,
                $context->roundingDecimals,
                $context->roundingInterval,
                $context->roundForNet,
                $context->currencyRoundingDecimals,
                $context->currencyRoundingInterval,
                $context->currencyRoundForNet,
            );
        }

        $this->assertSupported($criteria);

        $keywordGroups = [];
        $filters = [];
        $hasAvailable = false;
        foreach ($criteria->getFilters() as $filter) {
            if ($filter instanceof ProductAvailableFilter) {
                $hasAvailable = true;
            }

            $group = $this->keywordGroup($filter, $context);
            if ($group !== null) {
                $keywordGroups[] = $group;

                continue;
            }

            $filters[] = $this->filter($filter, $context);
        }
        if (!$hasAvailable) {
            $filters[] = $this->available($salesChannelContext->getSalesChannelId(), ProductVisibilityDefinition::VISIBILITY_LINK, $context);
        }

        $entityAggregations = [];
        $aggregations = [];
        foreach ($criteria->getAggregations() as $aggregation) {
            $aggregations[] = $this->aggregation($aggregation, [], $context, $entityAggregations);
        }

        $grouping = $criteria->getGroupFields();
        if (\count($grouping) > 1) {
            throw NotSupportedByEngine::feature(self::ENGINE, 'more than one group field');
        }

        $total = match ($criteria->getTotalCountMode()) {
            Criteria::TOTAL_COUNT_MODE_NONE => Total::NONE,
            Criteria::TOTAL_COUNT_MODE_EXACT => Total::EXACT,
            default => throw NotSupportedByEngine::feature(self::ENGINE, 'total count mode next pages'),
        };

        $ids = [];
        foreach ($criteria->getIds() as $id) {
            if (!\is_string($id)) {
                throw NotSupportedByEngine::feature(self::ENGINE, 'composite ids');
            }
            $ids[] = strtolower($id);
        }

        return new BridgedCriteria(
            new StoreCriteria(
                ids: $ids,
                filters: $filters,
                postFilters: array_map(fn (DalFilter $filter): Filter => $this->filter($filter, $context), $criteria->getPostFilters()),
                sortings: $this->sortings($criteria, $context),
                groupField: $grouping === [] ? null : $this->field($grouping[0]->getField(), $context),
                limit: $criteria->getLimit(),
                offset: $criteria->getOffset() ?? 0,
                total: $total,
                aggregations: $aggregations,
                search: $this->search($criteria, $keywordGroups),
            ),
            $context,
            $entityAggregations,
        );
    }

    /**
     * The document field of a DAL field path, {@see NotSupportedByEngine} when the document has no field of the same meaning.
     */
    public function field(string $dalField, StoreContext $context): string
    {
        $field = str_starts_with($dalField, 'product.') ? substr($dalField, 8) : $dalField;
        $field = self::PATHS[$field] ?? $field;

        if (\in_array(explode('.', $field)[0], self::NOT_DAL, true)) {
            throw NotSupportedByEngine::field($dalField, 'no DAL field of the read store document');
        }

        // throws for unknown, payload-only and nested fields
        $this->resolver->resolve($field, $context);

        return $field;
    }

    private function assertSupported(Criteria $criteria): void
    {
        if ($criteria->getTerm() !== null) {
            throw NotSupportedByEngine::feature(self::ENGINE, 'criteria term without search keyword queries');
        }
    }

    /**
     * The token group of a keyword filter of `ProductSearchBuilder`: `AND(EqualsAny(searchKeywords.keyword), Equals(searchKeywords.languageId))`.
     *
     * @return list<string>|null
     */
    private function keywordGroup(DalFilter $filter, StoreContext $context): ?array
    {
        if (!$filter instanceof MultiFilter || $filter->getOperator() !== MultiFilter::CONNECTION_AND || \count($filter->getQueries()) !== 2) {
            return null;
        }

        $keywords = null;
        $languageId = null;
        foreach ($filter->getQueries() as $query) {
            if ($query instanceof EqualsAnyFilter && $this->strip($query->getField()) === self::KEYWORD) {
                $keywords = array_values(array_map(strval(...), $query->getValue()));
            } elseif ($query instanceof EqualsFilter && $this->strip($query->getField()) === self::KEYWORD_LANGUAGE) {
                $languageId = (string) $query->getValue();
            }
        }

        if ($keywords === null || $languageId === null) {
            return null;
        }
        if (strtolower($languageId) !== $context->languageId) {
            throw NotSupportedByEngine::feature(self::ENGINE, 'keyword filter for another language than the context language');
        }

        return $keywords;
    }

    /**
     * @param list<list<string>> $keywordGroups
     */
    private function search(Criteria $criteria, array $keywordGroups): ?SearchTerm
    {
        $scored = [];
        $original = null;
        $originalScore = 1.0;
        foreach ($criteria->getQueries() as $query) {
            $inner = $query->getQuery();
            if (!$query instanceof ScoreQuery || $query->getScoreField() === null || $this->strip($query->getScoreField()) !== self::KEYWORD_RANKING) {
                throw NotSupportedByEngine::feature(self::ENGINE, 'score queries other than the search keyword queries');
            }
            if ($inner instanceof EqualsFilter && $this->strip($inner->getField()) === self::KEYWORD) {
                $scored[] = ['keyword' => (string) $inner->getValue(), 'score' => $query->getScore()];
            } elseif ($inner instanceof ContainsFilter && $this->strip($inner->getField()) === self::KEYWORD && $original === null) {
                $original = (string) $inner->getValue();
                $originalScore = $query->getScore();
            } else {
                throw NotSupportedByEngine::feature(self::ENGINE, 'score queries other than the search keyword queries');
            }
        }

        if ($keywordGroups === [] && $original === null && $scored === []) {
            return null;
        }
        if ($keywordGroups === [] || $original === null) {
            // score queries without keyword filters (or the other way round) rank or filter in a way the search term does not express
            throw NotSupportedByEngine::feature(self::ENGINE, 'search keyword queries without keyword filters');
        }

        return new SearchTerm($original, $scored, $keywordGroups, $originalScore, true);
    }

    private function filter(DalFilter $filter, StoreContext $context): Filter
    {
        if ($filter instanceof ProductAvailableFilter) {
            return $this->available($filter->getSalesChannelId(), $filter->getVisibility(), $context);
        }

        if ($filter instanceof NotFilter) {
            return new Not($this->operator($filter->getOperator()), array_values(array_map(fn (DalFilter $inner): Filter => $this->filter($inner, $context), $filter->getQueries())));
        }

        if ($filter instanceof MultiFilter) {
            return new Multi($this->operator($filter->getOperator()), array_values(array_map(fn (DalFilter $inner): Filter => $this->filter($inner, $context), $filter->getQueries())));
        }

        if ($filter instanceof EqualsFilter) {
            $field = $this->field($filter->getField(), $context);
            $value = $filter->getValue();

            return new Equals($field, $value === null ? null : $this->value($field, $value, $context));
        }

        if ($filter instanceof EqualsAnyFilter) {
            $field = $this->field($filter->getField(), $context);

            return new EqualsAny($field, array_values(array_map(fn (mixed $value): string|int|float|bool|null => $value === null ? null : $this->value($field, $value, $context), $filter->getValue())));
        }

        if ($filter instanceof ContainsFilter) {
            return new Contains($this->field($filter->getField(), $context), (string) $filter->getValue());
        }

        if ($filter instanceof PrefixFilter) {
            return new Prefix($this->field($filter->getField(), $context), $filter->getValue());
        }

        if ($filter instanceof RangeFilter) {
            $field = $this->field($filter->getField(), $context);
            $bound = fn (string $key): int|float|string|null => $filter->hasParameter($key) ? $this->bound($field, $filter->getParameter($key), $context) : null;

            return new Range($field, $bound(RangeFilter::GTE), $bound(RangeFilter::LTE), $bound(RangeFilter::GT), $bound(RangeFilter::LT));
        }

        throw NotSupportedByEngine::feature(self::ENGINE, $filter::class);
    }

    /**
     * `ProductAvailableFilter`: `visibilities.visibility >= v AND visibilities.salesChannelId = sc AND active = true`.
     */
    private function available(string $salesChannelId, int $visibility, StoreContext $context): Filter
    {
        if (strtolower($salesChannelId) !== $context->salesChannelId) {
            throw NotSupportedByEngine::feature(self::ENGINE, 'available filter for another sales channel than the context');
        }

        return new Multi(Multi::AND, [new Range('visibilities', gte: $visibility), new Equals('active', true)]);
    }

    private function operator(string $operator): string
    {
        return match ($operator) {
            MultiFilter::CONNECTION_AND => Multi::AND,
            MultiFilter::CONNECTION_OR => Multi::OR,
            default => throw NotSupportedByEngine::feature(self::ENGINE, 'multi filter operator ' . $operator),
        };
    }

    private function value(string $field, mixed $value, StoreContext $context): string|int|float|bool
    {
        if (!\is_scalar($value)) {
            throw NotSupportedByEngine::field($field, 'non-scalar filter value');
        }

        $type = $this->resolver->resolve($field, $context)->type;

        return match ($type) {
            FieldType::ID, FieldType::LIST => strtolower((string) $value),
            FieldType::DATETIME => (string) Normalizer::date($value),
            default => $value,
        };
    }

    private function bound(string $field, mixed $value, StoreContext $context): int|float|string|null
    {
        if ($value === null) {
            return null;
        }
        if (!\is_scalar($value) || \is_bool($value)) {
            throw NotSupportedByEngine::field($field, 'range bound is no number, string or date');
        }

        $type = $this->resolver->resolve($field, $context)->type;
        if ($type === FieldType::DATETIME) {
            return Normalizer::date($value);
        }

        return $value;
    }

    /**
     * @return list<Sorting>
     */
    private function sortings(Criteria $criteria, StoreContext $context): array
    {
        $sortings = [];
        foreach ($criteria->getSorting() as $sorting) {
            if ($sorting instanceof CountSorting) {
                throw NotSupportedByEngine::feature(self::ENGINE, 'count sorting');
            }
            if ($sorting->getNaturalSorting()) {
                throw NotSupportedByEngine::feature(self::ENGINE, 'natural sorting');
            }

            $field = $sorting->getField() === '_score' ? Sorting::SCORE : $this->field($sorting->getField(), $context);
            $sortings[] = new Sorting($field, strtoupper($sorting->getDirection()) === 'DESC' ? Sorting::DESC : Sorting::ASC);
        }

        return $sortings;
    }

    /**
     * @param list<Filter> $filters
     * @param array<string, string> $entityAggregations
     */
    private function aggregation(DalAggregation $aggregation, array $filters, StoreContext $context, array &$entityAggregations): Aggregation
    {
        if ($aggregation instanceof FilterAggregation) {
            $inner = $aggregation->getAggregation();
            if ($inner === null) {
                throw NotSupportedByEngine::feature(self::ENGINE, 'filter aggregation without aggregation');
            }

            return $this->aggregation($inner, [...$filters, ...array_values(array_map(fn (DalFilter $filter): Filter => $this->filter($filter, $context), $aggregation->getFilter()))], $context, $entityAggregations);
        }

        $name = $aggregation->getName();
        $field = $this->field($aggregation->getField(), $context);

        if ($aggregation instanceof TermsAggregation) {
            if ($aggregation->getSorting() !== null || $aggregation->getAggregation() !== null) {
                throw NotSupportedByEngine::feature(self::ENGINE, 'terms aggregation with sorting or nested aggregation');
            }

            return new Terms($name, $field, $filters, $aggregation->getLimit());
        }

        if ($aggregation instanceof EntityAggregation) {
            $entityAggregations[$name] = $aggregation->getEntity();

            return new Terms($name, $field, $filters);
        }

        return match (true) {
            $aggregation instanceof MinAggregation => new Min($name, $field, $filters),
            $aggregation instanceof MaxAggregation => new Max($name, $field, $filters),
            $aggregation instanceof AvgAggregation => new Avg($name, $field, $filters),
            $aggregation instanceof SumAggregation => new Sum($name, $field, $filters),
            $aggregation instanceof CountAggregation => new Count($name, $field, $filters),
            $aggregation instanceof StatsAggregation => new Stats($name, $field, $filters),
            default => throw NotSupportedByEngine::feature(self::ENGINE, $aggregation::class),
        };
    }

    private function strip(string $field): string
    {
        return str_starts_with($field, 'product.') ? substr($field, 8) : $field;
    }
}

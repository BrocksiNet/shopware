<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Bench;

use Doctrine\DBAL\Connection;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Content\Product\SalesChannel\Listing\AbstractProductListingRoute;
use Shopware\Core\Content\Product\SalesChannel\Search\AbstractProductSearchRoute;
use Shopware\Core\Framework\Adapter\Database\Dialect\IdBinding;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Bucket\TermsAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\AggregationResult as DalAggregationResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Bucket\TermsResult as DalTermsResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Metric\AvgResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Metric\CountResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Metric\EntityResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Metric\MaxResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Metric\MinResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Metric\StatsResult as DalStatsResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Metric\SumResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\CriteriaArrayConverter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\RequestCriteriaBuilder;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\OrmSpike\Harness\ImplementationRegistry;
use Shopware\Core\Framework\OrmSpike\Harness\Params;
use Shopware\Core\Framework\OrmSpike\Harness\ParamsFactory;
use Shopware\Core\Framework\OrmSpike\Harness\ScenarioNotSupported;
use Shopware\Core\Framework\ReadStore\Bridge\CriteriaBridge;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Aggregation\Aggregation;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Aggregation\Avg;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Aggregation\Count;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Aggregation\Sum;
use Shopware\Core\Framework\ReadStore\Port\NotSupportedByEngine;
use Shopware\Core\Framework\ReadStore\Port\Result\MetricResult;
use Shopware\Core\Framework\ReadStore\Port\Result\StatsResult;
use Shopware\Core\Framework\ReadStore\Port\Result\StoreResult;
use Shopware\Core\Framework\ReadStore\Port\Result\TermsResult;
use Shopware\Core\Framework\ReadStore\ReadStoreException;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\Context\AbstractSalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\Event\SalesChannelProcessCriteriaEvent;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Q4: runs the criteria corpus (`criteria-corpus.json`) on the DAL and on one read store and reports per entry
 * `match`, `fallback` (the bridge or the adapter threw {@see NotSupportedByEngine}, the caller would run the query on the DAL) or `diff`.
 *
 * The criteria are the ones the product routes really build: the command calls the Store API listing or search route with a request,
 * captures the criteria of the first `sales_channel.product.process.criteria` event (the `searchIds()` of `ProductListingLoader::resolveIds()`,
 * after `processCriteria()`, so with grouping, visibility and every listing filter) and runs it again on `sales_channel.product.repository`
 * (`searchIds()` and `aggregate()`, the loader aggregates the same criteria) and through {@see StoreImplementation::run()}.
 * Grouped results are compared by `displayGroup`, the DAL returns any product of a group.
 * Aggregations are compared on the values the DAL returns (a stats aggregation without `avg` returns no `avg`), entity aggregations by their ids.
 * `dal-defect`: the store differs only in aggregations of search criteria and equals the ground truth (the aggregation over the hit ids),
 * see {@see self::groundTruthAggregations()}; the exit code is a failure only for `diff`.
 * `--output-dir` gets the captured criteria (`CriteriaArrayConverter`) and the results as JSON, the fixtures of the capability matrix.
 *
 * @internal
 */
#[Package('framework')]
#[AsCommand(name: 'read-store:bench:corpus', description: 'Q4: runs the criteria corpus of the product routes on the DAL and on a read store')]
class CorpusCommand extends Command
{
    private const EVENT = 'sales_channel.product.process.criteria';

    /**
     * @param SalesChannelRepository<ProductCollection> $salesChannelProductRepository
     * @param EntityRepository<ProductCollection> $productRepository
     */
    public function __construct(
        private readonly ImplementationRegistry $registry,
        private readonly ParamsFactory $paramsFactory,
        private readonly AbstractSalesChannelContextFactory $contextFactory,
        private readonly AbstractProductListingRoute $listingRoute,
        private readonly AbstractProductSearchRoute $searchRoute,
        private readonly SalesChannelRepository $salesChannelProductRepository,
        private readonly EntityRepository $productRepository,
        private readonly RequestCriteriaBuilder $requestCriteriaBuilder,
        private readonly ProductDefinition $productDefinition,
        private readonly CriteriaArrayConverter $criteriaArrayConverter,
        private readonly CriteriaBridge $bridge,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('impl', null, InputOption::VALUE_REQUIRED, 'Read store implementation, e.g. store-array, store-sql-mysql, store-opensearch');
        $this->addOption('corpus', null, InputOption::VALUE_REQUIRED, 'Corpus file', __DIR__ . '/criteria-corpus.json');
        $this->addOption('only', null, InputOption::VALUE_REQUIRED, 'Comma-separated entry names');
        $this->addOption('output-dir', null, InputOption::VALUE_REQUIRED, 'Writes criteria/<name>.json and corpus-<impl>.json');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $key = (string) $input->getOption('impl');
        $implementation = $this->registry->get($key);
        if (!$implementation instanceof StoreImplementation) {
            $output->writeln(\sprintf('<error>%s is no read store implementation (store-*)</error>', $key));

            return self::FAILURE;
        }

        $corpus = json_decode((string) file_get_contents((string) $input->getOption('corpus')), true, 512, \JSON_THROW_ON_ERROR);
        \assert(\is_array($corpus) && \is_array($corpus['entries'] ?? null));
        $only = array_filter(explode(',', (string) $input->getOption('only')));

        $params = $this->paramsFactory->create();
        $context = $this->contextFactory->create(Uuid::randomHex(), $params->salesChannelId, [
            SalesChannelContextService::LANGUAGE_ID => $params->languageId,
            SalesChannelContextService::CURRENCY_ID => $params->currencyId,
        ]);
        $placeholders = $this->placeholders($params);

        $outputDir = $input->getOption('output-dir');
        if (\is_string($outputDir) && $outputDir !== '' && !is_dir($outputDir . '/criteria')) {
            mkdir($outputDir . '/criteria', 0777, true);
        }

        $results = [];
        $counts = ['match' => 0, 'dal-defect' => 0, 'fallback' => 0, 'diff' => 0, 'skipped' => 0];
        foreach ($corpus['entries'] as $entry) {
            $name = (string) $entry['name'];
            if ($only !== [] && !\in_array($name, $only, true)) {
                continue;
            }

            try {
                $entry = $this->resolve($entry, $placeholders);
            } catch (ReadStoreException $e) {
                $results[$name] = ['result' => 'skipped', 'reason' => $e->getMessage()];
                ++$counts['skipped'];
                $output->writeln(\sprintf('%-32s skipped  %s', $name, $e->getMessage()));

                continue;
            }

            [$criteria, $routeTotal] = $this->capture($entry, $params, $context);
            if (\is_string($outputDir) && $outputDir !== '') {
                file_put_contents($outputDir . '/criteria/' . $name . '.json', json_encode($this->criteriaArrayConverter->convert($criteria), \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR) . "\n");
            }

            $result = $this->compare($criteria, $routeTotal, $implementation, $params, $context);
            $results[$name] = $result;
            ++$counts[$result['result']];
            $output->writeln(\sprintf('%-32s %-8s %s', $name, $result['result'], $result['reason'] ?? ''));
        }

        $output->writeln(\sprintf('%s: %d match, %d dal-defect, %d fallback, %d diff, %d skipped', $key, $counts['match'], $counts['dal-defect'], $counts['fallback'], $counts['diff'], $counts['skipped']));

        if (\is_string($outputDir) && $outputDir !== '') {
            file_put_contents($outputDir . '/corpus-' . $key . '.json', json_encode(['impl' => $key, 'counts' => $counts, 'entries' => $results], \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR) . "\n");
        }

        return $counts['diff'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param array<string, mixed> $entry
     *
     * @return array{Criteria, int|null} the criteria `searchIds()` got and the total of the route
     */
    private function capture(array $entry, Params $params, SalesChannelContext $context): array
    {
        $captured = null;
        $listener = static function (SalesChannelProcessCriteriaEvent $event) use (&$captured): void {
            // deep copy: the loader and the repository keep changing their clones
            $captured ??= clone $event->getCriteria();
        };
        $this->dispatcher->addListener(self::EVENT, $listener, -10000);

        try {
            $total = match ($entry['route']) {
                'listing' => $this->listingRoute->load($params->categoryId, new Request($entry['query'] ?? []), $context, new Criteria())->getResult()->getTotal(),
                'search' => $this->searchRoute->load(new Request(['search' => $params->searchWord, ...($entry['query'] ?? [])]), $context, new Criteria())->getListingResult()->getTotal(),
                'criteria' => $this->salesChannelProductRepository->searchIds(
                    $this->requestCriteriaBuilder->fromArray($entry['criteria'], new Criteria(), $this->productDefinition, $context->getContext()),
                    $context
                )->getTotal(),
                default => throw ReadStoreException::invalidCriteria('Unknown route ' . $entry['route']),
            };
        } finally {
            $this->dispatcher->removeListener(self::EVENT, $listener);
        }

        if (!$captured instanceof Criteria) {
            throw ReadStoreException::invalidCriteria(\sprintf('%s: the route sent no product criteria', $entry['name']));
        }

        return [$captured, $total];
    }

    /**
     * @return array{result: string, reason?: string, dal?: array<string, mixed>, store?: array<string, mixed>, truth?: array<string, mixed>|null}
     */
    private function compare(Criteria $criteria, ?int $routeTotal, StoreImplementation $implementation, Params $params, SalesChannelContext $context): array
    {
        $grouped = $criteria->getGroupFields() !== [];

        $ids = $this->salesChannelProductRepository->searchIds($criteria, $context);
        $dal = [
            'total' => $ids->getTotal(),
            'items' => $grouped ? $this->displayGroups($ids->getIds(), $context) : array_map(strtolower(...), array_values(array_map(\strval(...), $ids->getIds()))),
            'aggregations' => $criteria->getAggregations() === [] ? [] : $this->dalAggregations($criteria, $context),
            'scores' => $criteria->getQueries() === [] ? [] : array_values(array_map(static fn (mixed $id): ?string => self::number($ids->getScore((string) $id)), $ids->getIds())),
        ];
        if ($criteria->getTotalCountMode() !== Criteria::TOTAL_COUNT_MODE_NONE && $routeTotal !== $dal['total']) {
            throw ReadStoreException::invalidCriteria(\sprintf('the captured criteria has %d hits on the DAL, the route %s: the capture is not the criteria the route ran', $dal['total'], (string) $routeTotal));
        }

        try {
            $bridged = $this->bridge->convert($criteria, $context);
            $result = $implementation->run($criteria, $params);
        } catch (NotSupportedByEngine|ScenarioNotSupported $e) {
            return ['result' => 'fallback', 'reason' => $e->getMessage()];
        }

        $store = [
            'total' => $criteria->getTotalCountMode() === Criteria::TOTAL_COUNT_MODE_NONE ? $dal['total'] : $result->total,
            'items' => array_map(static fn (array $document): string => strtolower((string) ($grouped ? ($document['displayGroup'] ?? '') : $document['id'])), $result->documents),
            'aggregations' => self::storeAggregations($result, $dal['aggregations'], $bridged->entityAggregations, $bridged->criteria->aggregations),
            'scores' => $result->scores === [] ? [] : array_map(static fn (array $document): ?string => self::number($result->scores[(string) $document['id']] ?? null), $result->documents),
        ];

        $reasons = [];
        if ($dal['total'] !== $store['total']) {
            $reasons[] = \sprintf('total %s vs %s', $dal['total'], $store['total']);
        }
        if ($dal['items'] !== $store['items']) {
            $sameSet = array_diff($dal['items'], $store['items']) === [] && array_diff($store['items'], $dal['items']) === [] && \count($dal['items']) === \count($store['items']);
            $reasons[] = $sameSet ? 'same items, other order' : \sprintf('items differ (%d only DAL, %d only store)', \count(array_diff($dal['items'], $store['items'])), \count(array_diff($store['items'], $dal['items'])));
        }
        $defects = [];
        $truth = null;
        foreach ($dal['aggregations'] as $name => $value) {
            if (($store['aggregations'][$name] ?? null) === $value) {
                continue;
            }
            if ($criteria->getQueries() !== []) {
                $truth ??= $this->groundTruthAggregations($criteria, $context);
                if (($store['aggregations'][$name] ?? null) === ($truth[$name] ?? null)) {
                    $defects[] = 'aggregation ' . $name . ' (DAL counts keyword rows, the store equals the aggregation over the hit ids)';
                    continue;
                }
            }
            $reasons[] = 'aggregation ' . $name;
        }

        if ($reasons === []) {
            return $defects === [] ? ['result' => 'match'] : ['result' => 'dal-defect', 'reason' => implode(', ', $defects), 'dal' => $dal, 'store' => $store, 'truth' => $truth];
        }
        array_push($reasons, ...$defects);

        return ['result' => 'diff', 'reason' => implode(', ', $reasons), 'dal' => $dal, 'store' => $store];
    }

    /**
     * @param array<string|int, mixed> $ids
     *
     * @return list<string>
     */
    private function displayGroups(array $ids, SalesChannelContext $context): array
    {
        $ids = array_values(array_map(static fn (mixed $id): string => strtolower((string) $id), $ids));
        if ($ids === []) {
            return [];
        }
        $products = $this->productRepository->search(new Criteria($ids), $context->getContext())->getEntities();

        return array_map(static fn (string $id): string => strtolower((string) $products->get($id)?->getDisplayGroup()), $ids);
    }

    /**
     * Search criteria join the keyword rows that pass the keyword filter, the DAL counts a product once per such row in a terms aggregation
     * (`COUNT(id)` over the join, docs/40-read-store-port-and-document.md). The ground truth runs the same aggregations on the ids of every hit
     * (without grouping, paging and post filters, like the aggregations of the DAL) without the keyword join.
     *
     * @return array<string, mixed>
     */
    private function groundTruthAggregations(Criteria $criteria, SalesChannelContext $context): array
    {
        $hits = clone $criteria;
        $hits->resetGroupFields()->resetPostFilters()->resetAggregations()->resetSorting();
        $hits->setLimit(null)->setOffset(null);
        $hits->setTotalCountMode(Criteria::TOTAL_COUNT_MODE_NONE);
        $ids = $this->salesChannelProductRepository->searchIds($hits, $context)->getIds();

        $truth = new Criteria($ids === [] ? null : array_values(array_map(\strval(...), $ids)));
        foreach ($criteria->getAggregations() as $aggregation) {
            $truth->addAggregation($aggregation);
        }

        return $this->dalAggregations($truth, $context);
    }

    /**
     * A terms aggregation with a limit and without a sorting is `GROUP BY ... LIMIT n` without `ORDER BY` in the DAL, the database returns any n
     * groups (on rs_k_corpus MySQL returned manufacturers with 165 and 166 products while eight had 169). The DAL side runs it without the limit
     * and keeps the buckets the port defines for a limit: highest count first, ties by the ascending key.
     *
     * @return array<string, mixed>
     */
    private function dalAggregations(Criteria $criteria, SalesChannelContext $context): array
    {
        $limits = [];
        $unlimited = clone $criteria;
        $unlimited->resetAggregations();
        foreach ($criteria->getAggregations() as $aggregation) {
            if ($aggregation instanceof TermsAggregation && $aggregation->getLimit() !== null && $aggregation->getSorting() === null) {
                $limits[$aggregation->getName()] = $aggregation->getLimit();
                $aggregation = new TermsAggregation($aggregation->getName(), $aggregation->getField(), null, null, $aggregation->getAggregation());
            }
            $unlimited->addAggregation($aggregation);
        }

        $normalized = [];
        foreach ($this->salesChannelProductRepository->aggregate($unlimited, $context) as $name => $aggregation) {
            $value = self::dalAggregation($aggregation);
            if (isset($limits[$name]) && \is_array($value)) {
                uksort($value, static fn (string|int $a, string|int $b): int => [$value[$b], (string) $a] <=> [$value[$a], (string) $b]);
                $value = \array_slice($value, 0, $limits[$name], true);
                ksort($value, \SORT_STRING);
            }
            $normalized[(string) $name] = $value;
        }
        ksort($normalized);

        return $normalized;
    }

    private static function dalAggregation(DalAggregationResult $aggregation): mixed
    {
        return match (true) {
            $aggregation instanceof DalTermsResult => self::counts(array_combine(
                array_map(static fn ($bucket): string => (string) $bucket->getKey(), $aggregation->getBuckets()),
                array_map(static fn ($bucket): int => $bucket->getCount(), $aggregation->getBuckets()),
            )),
            $aggregation instanceof EntityResult => self::keys(array_map(\strval(...), $aggregation->getEntities()->getKeys())),
            $aggregation instanceof DalStatsResult => array_filter([
                'min' => self::number($aggregation->getMin()),
                'max' => self::number($aggregation->getMax()),
                'avg' => self::number($aggregation->getAvg()),
                'sum' => self::number($aggregation->getSum()),
            ], static fn (?string $value): bool => $value !== null),
            $aggregation instanceof MinResult => self::number($aggregation->getMin()),
            $aggregation instanceof MaxResult => self::number($aggregation->getMax()),
            $aggregation instanceof AvgResult => self::number($aggregation->getAvg()),
            $aggregation instanceof SumResult => self::number($aggregation->getSum()),
            $aggregation instanceof CountResult => self::number($aggregation->getCount()),
            default => $aggregation::class,
        };
    }

    /**
     * Normalizes the store aggregations to the shape of the DAL ones (only the stats values the DAL returned).
     *
     * @param array<string, mixed> $dal
     * @param array<string, string> $entityAggregations
     * @param list<Aggregation> $requested
     *
     * @return array<string, mixed>
     */
    private static function storeAggregations(StoreResult $result, array $dal, array $entityAggregations, array $requested): array
    {
        $zeroWhenEmpty = [];
        foreach ($requested as $item) {
            if ($item instanceof Avg || $item instanceof Sum || $item instanceof Count) {
                $zeroWhenEmpty[$item->name] = true;
            }
        }

        $normalized = [];
        foreach ($result->aggregations as $name => $aggregation) {
            $normalized[$name] = match (true) {
                $aggregation instanceof TermsResult && isset($entityAggregations[$name]) => self::keys(array_map(\strval(...), array_keys($aggregation->counts()))),
                // with the null bucket, the DAL returns one for products without a value
                $aggregation instanceof TermsResult => self::counts(array_column(array_map(static fn (array $bucket): array => ['key' => (string) $bucket['key'], 'count' => $bucket['count']], $aggregation->buckets), 'count', 'key')),
                $aggregation instanceof StatsResult => array_filter([
                    'min' => isset($dal[$name]['min']) ? self::number($aggregation->min) : null,
                    'max' => isset($dal[$name]['max']) ? self::number($aggregation->max) : null,
                    'avg' => isset($dal[$name]['avg']) ? self::number($aggregation->avg) : null,
                    'sum' => isset($dal[$name]['sum']) ? self::number($aggregation->sum) : null,
                ], static fn (?string $value): bool => $value !== null),
                // DAL EntityAggregator casts Avg/Sum/Count null results to 0.0 / 0
                $aggregation instanceof MetricResult => self::number($aggregation->value ?? (isset($zeroWhenEmpty[$name]) ? 0 : null)),
                default => $aggregation::class,
            };
        }
        ksort($normalized);

        return $normalized;
    }

    /**
     * @param array<string|int, int> $counts
     *
     * @return array<string, int>
     */
    private static function counts(array $counts): array
    {
        $normalized = [];
        foreach ($counts as $key => $count) {
            $normalized[strtolower((string) $key)] = $count;
        }
        ksort($normalized);

        return $normalized;
    }

    /**
     * @param list<string> $keys
     *
     * @return list<string>
     */
    private static function keys(array $keys): array
    {
        $keys = array_map(strtolower(...), $keys);
        sort($keys);

        return $keys;
    }

    private static function number(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (\is_bool($value)) {
            return $value ? '1' : '0';
        }

        return rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');
    }

    /**
     * @return array<string, string>
     */
    private function placeholders(Params $params): array
    {
        $placeholders = [
            '$category' => $params->categoryId,
            '$searchWord' => $params->searchWord,
        ];
        foreach ($params->manufacturerIds as $index => $id) {
            $placeholders['$manufacturer' . $index] = $id;
        }

        // the options of the property groups products are assigned to, groups and options ordered by id
        $rows = $this->connection->fetchAllAssociative(
            'SELECT ' . IdBinding::text('o.property_group_id') . ' AS group_id, ' . IdBinding::text('o.id') . ' AS option_id
             FROM property_group_option o
             WHERE o.property_group_id IN (SELECT DISTINCT po.property_group_id FROM product_property pp INNER JOIN property_group_option po ON po.id = pp.property_group_option_id)
             ORDER BY o.property_group_id, o.id'
        );
        $groups = [];
        foreach ($rows as $row) {
            $groups[strtolower((string) $row['group_id'])][] = strtolower((string) $row['option_id']);
        }
        foreach (array_values($groups) as $groupIndex => $options) {
            foreach ($options as $optionIndex => $option) {
                $placeholders['$option:' . $groupIndex . ':' . $optionIndex] = $option;
            }
        }

        return $placeholders;
    }

    /**
     * Replaces the placeholders in all strings of the entry; a placeholder without a value skips the entry.
     *
     * @param array<string, mixed> $entry
     * @param array<string, string> $placeholders
     *
     * @return array<string, mixed>
     */
    private function resolve(array $entry, array $placeholders): array
    {
        array_walk_recursive($entry, static function (mixed &$value) use ($placeholders): void {
            if (!\is_string($value) || !str_contains($value, '$')) {
                return;
            }
            $value = (string) preg_replace_callback('/\$(option:\d+:\d+|[a-zA-Z]+\d*)/', static function (array $match) use ($placeholders): string {
                return $placeholders[$match[0]] ?? throw ReadStoreException::invalidCriteria(\sprintf('no value for %s on this database', $match[0]));
            }, $value);
        });

        return $entry;
    }
}

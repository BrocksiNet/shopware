<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Integration;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Shopware\Core\Content\Product\SalesChannel\Detail\AbstractProductDetailRoute;
use Shopware\Core\Content\Product\SalesChannel\Detail\ProductDetailRouteResponse;
use Shopware\Core\Content\Product\SalesChannel\Listing\AbstractProductListingRoute;
use Shopware\Core\Content\Product\SalesChannel\Listing\ProductListingRouteResponse;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Content\Product\SalesChannel\Search\AbstractProductSearchRoute;
use Shopware\Core\Content\Product\SalesChannel\Search\ProductSearchRouteResponse;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\OrmSpike\Harness\ParamsFactory;
use Shopware\Core\Framework\OrmSpike\Harness\StatementCounter;
use Shopware\Core\Framework\Struct\Struct;
use Shopware\Core\System\SalesChannel\Context\AbstractSalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Handoff R benchmark & verification command:
 * - `--mode=sql-accounting`: product-read SQL vs total route SQL for Detail (full & skipConfigurator/skipCmsPage), Listing, and Search
 * - `--mode=live-parts`: Q6 display-time live-parts timing (livePrices/liveStock ON vs OFF vs DTO)
 * - `--mode=equality`: 50-request Store API response JSON equality check (DAL vs Read Store Shape (a))
 * - `--mode=all`: runs all three modes
 *
 * @internal
 */
#[Package('framework')]
#[AsCommand(name: 'read-store:bench:integration', description: 'Handoff R: SQL accounting, Q6 live parts, and 50-request Store API equality check')]
final class IntegrationBenchCommand extends Command
{
    public function __construct(
        private readonly ReadStoreConfig $config,
        private readonly ReadStoreGateway $gateway,
        private readonly AbstractProductDetailRoute $detailRoute,
        private readonly AbstractProductListingRoute $listingRoute,
        private readonly AbstractProductSearchRoute $searchRoute,
        private readonly AbstractSalesChannelContextFactory $contextFactory,
        private readonly ParamsFactory $paramsFactory,
        private readonly StatementCounter $counter,
        private readonly Connection $connection,
        private readonly ClockInterface $clock,
        private readonly ?SourceDocumentReaderSwitch $sourceReader = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('store', null, InputOption::VALUE_REQUIRED, 'Read store key (array, sql-mysql, opensearch, opensearch-valkey, mongodb), or o1a: O1 (ES search, SQL read) against O1a (ES search, `_source` read)', 'opensearch')
            ->addOption('mode', null, InputOption::VALUE_REQUIRED, 'sql-accounting|live-parts|equality|all', 'all')
            ->addOption('iterations', null, InputOption::VALUE_REQUIRED, 'Timing iterations per variant', '20')
            ->addOption('warmup', null, InputOption::VALUE_REQUIRED, 'Warmup iterations per variant', '3');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $store = (string) $input->getOption('store');
        $mode = (string) $input->getOption('mode');
        $iterations = max(1, (int) $input->getOption('iterations'));
        $warmup = max(0, (int) $input->getOption('warmup'));

        $params = $this->paramsFactory->create();
        $context = $this->contextFactory->create('rs-integration-token', $params->salesChannelId);

        if ($mode === 'sql-accounting' || $mode === 'all') {
            $this->runSqlAccounting($output, $store, $params->variantId, $params->categoryId, $context, $iterations, $warmup);
        }

        if ($mode === 'live-parts' || $mode === 'all') {
            $this->runLiveParts($output, $store, $params->variantId, $params->categoryId, $context, $iterations, $warmup);
        }

        if ($mode === 'equality' || $mode === 'all') {
            $this->runEquality($output, $store, $params->categoryId, $context);
        }

        $this->config->reset();
        $this->sourceReader?->setRuntimeEnabled(true);

        return self::SUCCESS;
    }

    private function runSqlAccounting(
        OutputInterface $output,
        string $store,
        string $productId,
        string $categoryId,
        SalesChannelContext $context,
        int $iterations,
        int $warmup,
    ): void {
        $output->writeln(\sprintf('=== SQL Accounting (store=%s, iterations=%d, warmup=%d) ===', $store, $iterations, $warmup));

        $modes = [
            'dal (O0)' => [false, null, ReadStoreConfig::SHAPE_ENTITY],
            "store-$store shape(a) entity" => [true, $store, ReadStoreConfig::SHAPE_ENTITY],
            "store-$store shape(b) dto" => [true, $store, ReadStoreConfig::SHAPE_DTO],
        ];
        if ($this->isO1a($store)) {
            // wave 10 A: the gateway stays off, the document reader is switched per mode (needs SHOPWARE_ES_ENABLED=1, SHOPWARE_ES_SOURCE_DOCUMENT=1)
            $modes = [
                'O1 (ES search + SQL read)' => [false, null, ReadStoreConfig::SHAPE_ENTITY, false],
                'O1a (ES search + _source read)' => [false, null, ReadStoreConfig::SHAPE_ENTITY, true],
            ];
        }

        $routes = [
            'Detail (full)' => fn (): mixed => $this->detailRoute->load($productId, new Request(), $context, new Criteria()),
            'Detail (skipCfg+skipCms)' => fn (): mixed => $this->detailRoute->load($productId, new Request(['skipConfigurator' => '1', 'skipCmsPage' => '1']), $context, new Criteria()),
            'Listing (cat, p1, 24)' => fn (): mixed => $this->listingRoute->load($categoryId, new Request(['limit' => 24, 'p' => 1]), $context, new Criteria()),
            'Search (term=Hotel)' => fn (): mixed => $this->searchRoute->load(new Request(['search' => 'Hotel', 'limit' => 24]), $context, new Criteria()),
        ];

        foreach ($routes as $routeName => $call) {
            $output->writeln(\sprintf('-- %s --', $routeName));
            foreach ($modes as $label => $mode) {
                [$enabled, $key, $shape] = $mode;
                $this->config->override($enabled, $key, $shape);
                $this->sourceReader?->setRuntimeEnabled($mode[3] ?? true);
                $this->sourceReader?->resetStats();
                $this->gateway->resetMetrics();

                for ($i = 0; $i < $warmup; ++$i) {
                    $call();
                }

                $times = [];
                $stmts = 0;
                for ($i = 0; $i < $iterations; ++$i) {
                    $this->counter->reset();
                    $start = (float) $this->clock->now()->format('U.u');
                    $call();
                    $times[] = ((float) $this->clock->now()->format('U.u') - $start) * 1000.0;
                    $stmts = $this->counter->reset();
                }

                sort($times);
                $p50 = $times[(int) floor((\count($times) - 1) * 0.5)];
                $p95 = $times[(int) min(\count($times) - 1, ceil(\count($times) * 0.95) - 1)];
                $output->writeln(\sprintf(
                    '  %-34s p50=%6.2F ms  p95=%6.2F ms  total_sql=%2d  hits=%d  fallbacks=%d',
                    $label,
                    $p50,
                    $p95,
                    $stmts,
                    $this->gateway->hits(),
                    $this->gateway->fallbacks(),
                ));
                foreach ($this->gateway->fallbackReasons() as $reason => $cnt) {
                    $output->writeln(\sprintf('    fallback (%dx): %s', $cnt, $reason));
                }
                if ($this->isO1a($store) && $this->sourceReader !== null) {
                    $output->writeln('    source reader: ' . json_encode($this->sourceReader->stats(), \JSON_THROW_ON_ERROR));
                }
            }
        }
    }

    private function isO1a(string $store): bool
    {
        return $store === 'o1a' && $this->sourceReader !== null;
    }

    private function runLiveParts(
        OutputInterface $output,
        string $store,
        string $productId,
        string $categoryId,
        SalesChannelContext $context,
        int $iterations,
        int $warmup,
    ): void {
        $output->writeln(\sprintf('=== Q6 Live Parts Timing (store=%s, iterations=%d, warmup=%d) ===', $store, $iterations, $warmup));

        $configs = [
            'entity + livePrices ON  + liveStock ON ' => [ReadStoreConfig::SHAPE_ENTITY, true, true],
            'entity + livePrices ON  + liveStock OFF' => [ReadStoreConfig::SHAPE_ENTITY, true, false],
            'entity + livePrices OFF + liveStock OFF' => [ReadStoreConfig::SHAPE_ENTITY, false, false],
            'dto    + no subscriber (shape b)       ' => [ReadStoreConfig::SHAPE_DTO, false, false],
        ];

        $routes = [
            'R1 Detail (skipCfg+skipCms)' => fn (): mixed => $this->detailRoute->load($productId, new Request(['skipConfigurator' => '1', 'skipCmsPage' => '1']), $context, new Criteria()),
            'R2 Listing (cat, 24)       ' => fn (): mixed => $this->listingRoute->load($categoryId, new Request(['limit' => 24, 'p' => 1]), $context, new Criteria()),
            'R3b Search (Hotel, 24)     ' => fn (): mixed => $this->searchRoute->load(new Request(['search' => 'Hotel', 'limit' => 24]), $context, new Criteria()),
        ];

        foreach ($routes as $routeName => $call) {
            $output->writeln(\sprintf('-- %s --', trim($routeName)));
            foreach ($configs as $label => [$shape, $livePrices, $liveStock]) {
                $this->config->override(true, $store, $shape, $livePrices, $liveStock);
                for ($i = 0; $i < $warmup; ++$i) {
                    $call();
                }
                $times = [];
                $stmts = 0;
                for ($i = 0; $i < $iterations; ++$i) {
                    $this->counter->reset();
                    $start = (float) $this->clock->now()->format('U.u');
                    $call();
                    $times[] = ((float) $this->clock->now()->format('U.u') - $start) * 1000.0;
                    $stmts = $this->counter->reset();
                }
                sort($times);
                $p50 = $times[(int) floor((\count($times) - 1) * 0.5)];
                $p95 = $times[(int) min(\count($times) - 1, ceil(\count($times) * 0.95) - 1)];
                $output->writeln(\sprintf('  %s  p50=%6.2F ms  p95=%6.2F ms  sql=%2d', $label, $p50, $p95, $stmts));
            }
        }
    }

    private function runEquality(
        OutputInterface $output,
        string $store,
        string $defaultCategoryId,
        SalesChannelContext $context,
    ): void {
        $output->writeln(\sprintf('=== 50-Request Store API Equality Check (DAL vs store-%s shape a) ===', $store));

        $productIds = $this->connection->fetchFirstColumn(
            'SELECT LOWER(HEX(p.id)) FROM product p
             INNER JOIN product_visibility pv ON pv.product_id = p.id AND pv.product_version_id = p.version_id
             WHERE p.active = 1 AND p.display_group IS NOT NULL AND pv.sales_channel_id = UNHEX(:sc)
             ORDER BY p.product_number ASC LIMIT 10',
            ['sc' => $context->getSalesChannelId()],
        );
        $categoryIds = $this->connection->fetchFirstColumn(
            'SELECT DISTINCT LOWER(HEX(pc.category_id)) FROM product_category_tree pc
             INNER JOIN category c ON c.id = pc.category_id AND c.active = 1
             ORDER BY pc.category_id ASC LIMIT 5',
        );
        if ($categoryIds === []) {
            $categoryIds = [$defaultCategoryId];
        }
        $manufacturerIds = $this->connection->fetchFirstColumn(
            'SELECT DISTINCT LOWER(HEX(product_manufacturer_id)) FROM product WHERE active = 1 AND product_manufacturer_id IS NOT NULL ORDER BY product_manufacturer_id ASC LIMIT 5',
        );

        /** @var list<array{name: string, call: callable(): array<string, mixed>}> $cases */
        $cases = [];

        foreach ($productIds as $idx => $pid) {
            $pid = (string) $pid;
            $cases[] = [
                'name' => \sprintf('detail-full-%02d', $idx + 1),
                'call' => fn (): array => $this->normalizeDetail($this->detailRoute->load($pid, new Request(), $context, new Criteria())),
            ];
            $cases[] = [
                'name' => \sprintf('detail-skip-%02d', $idx + 1),
                'call' => fn (): array => $this->normalizeDetail($this->detailRoute->load($pid, new Request(['skipConfigurator' => '1', 'skipCmsPage' => '1']), $context, new Criteria())),
            ];
        }

        $listingSpecs = [
            ['order' => 'name-asc', 'p' => 1, 'limit' => 24],
            ['order' => 'name-desc', 'p' => 1, 'limit' => 24],
            ['order' => 'price-asc', 'p' => 1, 'limit' => 24],
            ['order' => 'price-desc', 'p' => 1, 'limit' => 24],
            ['order' => 'name-asc', 'p' => 2, 'limit' => 12],
            ['order' => 'name-asc', 'min-price' => 50, 'max-price' => 300, 'limit' => 24],
            ['order' => 'name-asc', 'shipping-free' => '1', 'limit' => 24],
            ['order' => 'name-asc', 'rating' => 3, 'limit' => 24],
        ];
        $listingIdx = 0;
        foreach ($categoryIds as $catId) {
            foreach (\array_slice($listingSpecs, 0, 4) as $spec) {
                if ($listingIdx >= 20) {
                    break 2;
                }
                ++$listingIdx;
                $catIdStr = (string) $catId;
                $cases[] = [
                    'name' => \sprintf('listing-%02d-%s', $listingIdx, (string) ($spec['order'] ?? 'default')),
                    'call' => fn (): array => $this->normalizeListing($this->listingRoute->load($catIdStr, new Request($spec), $context, new Criteria())),
                ];
            }
        }
        foreach ($manufacturerIds as $mfrId) {
            if ($listingIdx >= 20) {
                break;
            }
            ++$listingIdx;
            $mfrIdStr = (string) $mfrId;
            $cases[] = [
                'name' => \sprintf('listing-%02d-mfr', $listingIdx),
                'call' => fn (): array => $this->normalizeListing($this->listingRoute->load($defaultCategoryId, new Request(['manufacturer' => $mfrIdStr, 'order' => 'name-asc', 'limit' => 24]), $context, new Criteria())),
            ];
        }

        $searchSpecs = [
            ['search' => 'Hotel', 'limit' => 24],
            ['search' => 'Hotel', 'order' => 'name-asc', 'limit' => 24],
            ['search' => 'Hotel', 'order' => 'name-desc', 'limit' => 24],
            ['search' => 'Hotel', 'order' => 'price-asc', 'limit' => 24],
            ['search' => 'Hotel', 'min-price' => 20, 'max-price' => 200, 'order' => 'name-asc', 'limit' => 24],
            ['search' => 'Hotel', 'p' => 2, 'limit' => 12, 'order' => 'name-asc'],
            ['search' => 'Hotel', 'shipping-free' => '1', 'order' => 'name-asc', 'limit' => 24],
            ['search' => 'Hotel', 'rating' => 2, 'order' => 'name-asc', 'limit' => 24],
            ['search' => 'Shop', 'order' => 'name-asc', 'limit' => 24],
            ['search' => 'nonexistenttermxyz999', 'limit' => 24],
        ];
        foreach ($searchSpecs as $idx => $spec) {
            $cases[] = [
                'name' => \sprintf('search-%02d', $idx + 1),
                'call' => fn (): array => $this->normalizeListing($this->searchRoute->load(new Request($spec), $context, new Criteria())),
            ];
        }

        $equal = 0;
        $fallbackCount = 0;
        $diffs = [];

        $o1a = $this->isO1a($store);
        foreach ($cases as $case) {
            $this->config->override(false);
            $this->sourceReader?->setRuntimeEnabled(!$o1a);
            $expected = $case['call']();

            if ($o1a) {
                \assert($this->sourceReader !== null);
                $this->sourceReader->setRuntimeEnabled(true);
                $this->sourceReader->resetStats();
                $actual = $case['call']();
                $stats = $this->sourceReader->stats();
                $wasFallback = ($stats['document'] ?? 0) === 0;
            } else {
                $this->config->override(true, $store, ReadStoreConfig::SHAPE_ENTITY, true, true);
                $this->gateway->resetMetrics();
                $actual = $case['call']();
                $wasFallback = $this->gateway->fallbacks() > 0 && $this->gateway->hits() === 0;
            }
            if ($wasFallback) {
                ++$fallbackCount;
            }

            $diff = $this->firstDiff($expected, $actual, '$');
            if ($diff === null) {
                ++$equal;
            } else {
                $diffs[] = $case['name'] . ($wasFallback ? ' [fallback]' : '') . ': ' . $diff;
            }
        }

        $output->writeln(\sprintf(
            'Equality summary: %d / %d identical (%d served by read store, %d fell back to DAL), %d diffs',
            $equal,
            \count($cases),
            \count($cases) - $fallbackCount,
            $fallbackCount,
            \count($diffs),
        ));
        foreach ($diffs as $line) {
            $output->writeln('  DIFF: ' . $line);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeDetail(ProductDetailRouteResponse $response): array
    {
        $product = $response->getProduct();
        $configurator = $response->getConfigurator();

        return [
            'id' => $product->getId(),
            'parentId' => $product->getParentId(),
            'productNumber' => $product->getProductNumber(),
            'name' => $product->getTranslation('name'),
            'active' => $product->getActive(),
            'available' => $product->getAvailable(),
            'stock' => $product->getStock(),
            'availableStock' => $product->getAvailableStock(),
            'manufacturerId' => $product->getManufacturerId(),
            'manufacturerName' => $product->getManufacturer()?->getTranslation('name'),
            'taxRate' => $product->getTax()?->getTaxRate(),
            'calculatedUnitPrice' => $product->getCalculatedPrice()->getUnitPrice(),
            'calculatedTotalPrice' => $product->getCalculatedPrice()->getTotalPrice(),
            'calculatedMaxPurchase' => $product->getCalculatedMaxPurchase(),
            'isNew' => $product->isNew(),
            'seoCategoryId' => $product->getSeoCategory()?->getId(),
            'cmsPageId' => $product->getCmsPage()?->getId(),
            'configuratorGroupCount' => $configurator ? $configurator->count() : null,
            'sortedPropertyGroups' => $product->getSortedProperties()?->count() ?? 0,
            'variationCount' => \count($product->getVariation()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeListing(ProductListingRouteResponse|ProductSearchRouteResponse $response): array
    {
        $result = $response instanceof ProductListingRouteResponse ? $response->getResult() : $response->getListingResult();
        $items = [];
        foreach ($result->getEntities() as $product) {
            if (!$product instanceof SalesChannelProductEntity) {
                continue;
            }
            $items[] = [
                'displayGroup' => $product->getDisplayGroup(),
                'available' => $product->getAvailable(),
            ];
        }

        $priceAgg = $result->getAggregations()->get('price');
        $priceSummary = null;
        if ($priceAgg instanceof Struct) {
            $vars = $priceAgg->getVars();
            $priceSummary = [
                'min' => isset($vars['min']) ? round((float) $vars['min'], 2) : null,
                'max' => isset($vars['max']) ? round((float) $vars['max'], 2) : null,
            ];
        }

        return [
            'total' => $result->getTotal(),
            'count' => \count($items),
            'items' => $items,
            'price' => $priceSummary,
        ];
    }

    private function firstDiff(mixed $expected, mixed $actual, string $path): ?string
    {
        if (\is_float($expected) && \is_float($actual)) {
            return abs($expected - $actual) < 0.001 ? null : \sprintf('%s expected %.4F got %.4F', $path, $expected, $actual);
        }
        if (\is_array($expected) && \is_array($actual)) {
            $keys = array_unique([...array_keys($expected), ...array_keys($actual)]);
            foreach ($keys as $key) {
                if (!\array_key_exists($key, $expected)) {
                    return \sprintf('%s.%s unexpected key', $path, (string) $key);
                }
                if (!\array_key_exists($key, $actual)) {
                    return \sprintf('%s.%s missing key', $path, (string) $key);
                }
                $sub = $this->firstDiff($expected[$key], $actual[$key], $path . '.' . (string) $key);
                if ($sub !== null) {
                    return $sub;
                }
            }

            return null;
        }

        if ($expected !== $actual) {
            return \sprintf('%s expected %s got %s', $path, json_encode($expected) ?: 'null', json_encode($actual) ?: 'null');
        }

        return null;
    }
}

<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Integration;

use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Category\Service\CategoryBreadcrumbBuilder;
use Shopware\Core\Content\Cms\CmsPageEntity;
use Shopware\Core\Content\Cms\DataResolver\ResolverContext\EntityResolverContext;
use Shopware\Core\Content\Cms\SalesChannel\SalesChannelCmsPageLoaderInterface;
use Shopware\Core\Content\Product\Aggregate\ProductVisibility\ProductVisibilityDefinition;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Content\Product\SalesChannel\Detail\Event\ResolveVariantIdEvent;
use Shopware\Core\Content\Product\SalesChannel\Detail\ProductConfiguratorLoader;
use Shopware\Core\Content\Product\SalesChannel\Detail\ProductDetailRouteResponse;
use Shopware\Core\Content\Product\SalesChannel\Listing\ProductListingLoader;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductDefinition;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Framework\Adapter\Cache\CacheTagCollector;
use Shopware\Core\Framework\DataAbstractionLayer\Cache\EntityCacheKeyGenerator;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotEqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Grouping\FieldGrouping;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\ReadStore\Bridge\CriteriaBridge;
use Shopware\Core\Framework\ReadStore\Port\AggregationAware;
use Shopware\Core\Framework\ReadStore\Port\FilterAware;
use Shopware\Core\Framework\ReadStore\Port\NotSupportedByEngine;
use Shopware\Core\Framework\ReadStore\Port\SearchAware;
use Shopware\Core\Framework\ReadStore\Port\Storage;
use Shopware\Core\Framework\ReadStore\Projector\StorageRegistry;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Serves product Store API routes from the configured read store and falls back cleanly to the DAL whenever
 * the criteria is unsupported, the store has no live generation, or the backend fails.
 *
 * @internal
 */
#[Package('framework')]
final class ReadStoreGateway
{
    private const SKIP_CONFIGURATOR = 'skipConfigurator';
    private const SKIP_CMS_PAGE = 'skipCmsPage';

    private int $hits = 0;

    private int $fallbacks = 0;

    /**
     * @var array<string, int>
     */
    private array $fallbackReasons = [];

    public function __construct(
        private readonly ReadStoreConfig $config,
        private readonly StorageRegistry $storages,
        private readonly CriteriaBridge $bridge,
        private readonly DocumentHydrator $hydrator,
        private readonly SystemConfigService $systemConfig,
        private readonly CategoryBreadcrumbBuilder $breadcrumbBuilder,
        private readonly ProductConfiguratorLoader $configuratorLoader,
        private readonly SalesChannelCmsPageLoaderInterface $cmsPageLoader,
        private readonly SalesChannelProductDefinition $productDefinition,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly CacheTagCollector $cacheTagCollector,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function detail(
        string $productId,
        Request $request,
        SalesChannelContext $context,
        Criteria $criteria,
    ): ?ProductDetailRouteResponse {
        if (!$this->config->isEnabled($context)) {
            return null;
        }

        // Only handle plain detail lookups without extra custom criteria filters/associations that require DAL joins
        if ($criteria->getFilters() !== [] || $criteria->getPostFilters() !== [] || $criteria->getQueries() !== []) {
            $this->recordFallback('detail', 'criteria has custom filters or queries');

            return null;
        }

        if (!Uuid::isValid($productId)) {
            return null;
        }

        $storeKey = $this->config->storeKey($context);

        try {
            $storage = $this->storages->storage($storeKey);
            $requestedId = strtolower($productId);
            $doc = $storage->get($requestedId);
            if ($doc === null) {
                $this->recordFallback('detail', 'document not found in read store');

                return null;
            }

            // Answer checkVariantListingConfig() from the read document instead of SQL
            $mainVariantId = null;
            $vlc = $doc['variantListingConfig'] ?? null;
            if (\is_array($vlc)) {
                $displayParent = (bool) ($vlc['displayParent'] ?? false);
                if (!$displayParent || isset($vlc['mainVariantId'])) {
                    $mainVariantId = \is_string($vlc['mainVariantId'] ?? null) ? strtolower($vlc['mainVariantId']) : null;
                }
            }

            $resolveEvent = new ResolveVariantIdEvent($productId, $mainVariantId, $context);
            $this->dispatcher->dispatch($resolveEvent);
            $resolvedVariantId = $resolveEvent->getResolvedVariantId();

            if ($resolvedVariantId !== null && strtolower($resolvedVariantId) !== $requestedId) {
                $variantDoc = $storage->get(strtolower($resolvedVariantId));
                if ($variantDoc !== null) {
                    $doc = $variantDoc;
                } else {
                    $this->recordFallback('detail', 'resolved variant not found in read store');

                    return null;
                }
            }

            if (!$this->isAvailable($doc, $context, ProductVisibilityDefinition::VISIBILITY_LINK)) {
                $this->recordFallback('detail', 'product not available in read store');

                return null;
            }

            $shape = $this->config->shape($context);
            $collection = $this->hydrator->hydrateEntities(
                [$doc],
                $context,
                $shape === ReadStoreConfig::SHAPE_ENTITY && $this->config->livePrices(),
                $shape === ReadStoreConfig::SHAPE_ENTITY && $this->config->liveStock(),
                $criteria,
            );
            $product = $collection->first();
            if (!$product instanceof SalesChannelProductEntity) {
                $this->recordFallback('detail', 'hydration returned no entity');

                return null;
            }

            $parent = $product->getParentId() ?? $product->getId();
            $this->cacheTagCollector->addTag(EntityCacheKeyGenerator::buildProductTag($parent));

            // Surrounding route work kept on SQL (breadcrumb, configurator, CMS page)
            $product->setSeoCategory($this->breadcrumbBuilder->getProductSeoCategory($product, $context));

            $loadConfigurator = !$request->query->getBoolean(self::SKIP_CONFIGURATOR);
            $configurator = $loadConfigurator ? $this->configuratorLoader->load($product, $context) : null;

            $loadCmsPage = !$request->query->getBoolean(self::SKIP_CMS_PAGE);
            $pageId = $product->getCmsPageId();
            if ($loadCmsPage && $pageId) {
                $resolverContext = new EntityResolverContext($context, $request, $this->productDefinition, clone $product);
                $cmsCriteria = new Criteria([$pageId]);
                $pages = $this->cmsPageLoader->load($request, $cmsCriteria, $context, null, $resolverContext);
                $cmsPage = $pages->getEntities()->first();
                if ($cmsPage instanceof CmsPageEntity) {
                    $product->setCmsPage($cmsPage);
                }
            }

            ++$this->hits;

            return new ProductDetailRouteResponse($product, $configurator);
        } catch (\Throwable $e) {
            $this->recordFallback('detail', $e->getMessage());

            return null;
        }
    }

    /**
     * @return EntitySearchResult<ProductCollection>|null
     */
    public function listing(Criteria $criteria, SalesChannelContext $context): ?EntitySearchResult
    {
        if (!$this->config->isEnabled($context)) {
            return null;
        }

        $storeKey = $this->config->storeKey($context);

        try {
            $storage = $this->storages->storage($storeKey);

            // Mirror ProductListingLoader::addGrouping() unless suppressed
            $effective = clone $criteria;
            if (!$effective->hasState(ProductListingLoader::STATE_SKIP_ADD_GROUPING) && !$this->hasOptionPostFilter($effective)) {
                $effective->addGroupField(new FieldGrouping('displayGroup'));
                $effective->addFilter(new NotEqualsFilter('displayGroup', null));
            }

            $bridged = $this->bridge->convert($effective, $context);
            $storeCriteria = $bridged->criteria;
            $storeContext = $bridged->context;

            if ($storeCriteria->search !== null) {
                if (!$storage instanceof SearchAware) {
                    throw NotSupportedByEngine::feature($storeKey, 'search');
                }
                $storeResult = $storage->search($storeCriteria, $storeContext);
            } else {
                if (!$storage instanceof FilterAware) {
                    throw NotSupportedByEngine::feature($storeKey, 'filter');
                }
                $storeResult = $storage->filter($storeCriteria, $storeContext);
            }

            $portAggregations = [];
            if ($storeCriteria->aggregations !== []) {
                if (!$storage instanceof AggregationAware) {
                    throw NotSupportedByEngine::feature($storeKey, 'aggregations');
                }
                $portAggregations = $storage->aggregate($storeCriteria, $storeContext);
            }

            // Answer loadPreviews() from the read store documents (0 SQL)
            $baseStorage = $this->storages->storage($storeKey);
            $documents = $this->resolvePreviewsInStore($storeResult->documents, $baseStorage, $context);

            $dalAggregations = $this->hydrator->hydrateAggregations($portAggregations, $bridged, $context);

            $shape = $this->config->shape($context);
            if ($shape === ReadStoreConfig::SHAPE_DTO) {
                $dtos = $this->hydrator->hydrateDtos($documents, $context);
                ++$this->hits;

                /** @var EntitySearchResult<ProductCollection> $dtoResult */
                $dtoResult = new EntitySearchResult(
                    ProductDefinition::ENTITY_NAME,
                    $storeResult->total ?? \count($documents),
                    $dtos,
                    $dalAggregations,
                    $criteria,
                    $context->getContext(),
                );

                return $dtoResult;
            }

            $entities = $this->hydrator->hydrateEntities(
                $documents,
                $context,
                $this->config->livePrices(),
                $this->config->liveStock(),
                $criteria,
            );

            ++$this->hits;

            return new EntitySearchResult(
                ProductDefinition::ENTITY_NAME,
                $storeResult->total ?? \count($documents),
                $entities,
                $dalAggregations,
                $criteria,
                $context->getContext(),
            );
        } catch (\Throwable $e) {
            $this->recordFallback('listing', $e->getMessage());

            return null;
        }
    }

    public function hits(): int
    {
        return $this->hits;
    }

    public function fallbacks(): int
    {
        return $this->fallbacks;
    }

    /**
     * @return array<string, int>
     */
    public function fallbackReasons(): array
    {
        return $this->fallbackReasons;
    }

    public function resetMetrics(): void
    {
        $this->hits = 0;
        $this->fallbacks = 0;
        $this->fallbackReasons = [];
    }

    /**
     * @param array<string, mixed> $doc
     */
    private function isAvailable(array $doc, SalesChannelContext $context, int $minVisibility): bool
    {
        if (($doc['active'] ?? false) !== true) {
            return false;
        }
        $visibilities = $doc['visibilities'] ?? [];
        $visibility = \is_array($visibilities) ? ($visibilities[$context->getSalesChannelId()] ?? null) : null;
        if (!\is_int($visibility) || $visibility < $minVisibility) {
            return false;
        }
        if ($this->systemConfig->getBool('core.listing.hideCloseoutProductsWhenOutOfStock', $context->getSalesChannelId())) {
            if (($doc['isCloseout'] ?? false) === true && ((int) ($doc['availableStock'] ?? 0)) <= 0) {
                return false;
            }
        }

        return true;
    }

    private function hasOptionPostFilter(Criteria $criteria): bool
    {
        foreach ($criteria->getPostFilters() as $filter) {
            foreach ($filter->getFields() as $field) {
                $stripped = preg_replace('/^product\./', '', $field) ?? $field;
                if (\in_array($stripped, ['options.id', 'properties.id', 'optionIds', 'propertyIds'], true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Replaces child variants with their configured `mainVariantId` or `parentId` from `variantListingConfig` via `mget()`.
     *
     * @param list<array<string, mixed>> $documents
     *
     * @return list<array<string, mixed>>
     */
    private function resolvePreviewsInStore(array $documents, Storage $storage, SalesChannelContext $context): array
    {
        $remapTargets = [];
        foreach ($documents as $index => $doc) {
            $parentId = $doc['parentId'] ?? null;
            $vlc = $doc['variantListingConfig'] ?? null;
            if (!\is_string($parentId) || !\is_array($vlc)) {
                continue;
            }
            $target = null;
            if (\is_string($vlc['mainVariantId'] ?? null) && $vlc['mainVariantId'] !== '') {
                $target = strtolower($vlc['mainVariantId']);
            }
            if ((bool) ($vlc['displayParent'] ?? false) === true) {
                $target = strtolower($parentId);
            }
            if ($target !== null && $target !== strtolower((string) $doc['id'])) {
                $remapTargets[$index] = $target;
            }
        }

        if ($remapTargets === []) {
            return $documents;
        }

        $loaded = $storage->mget(array_values(array_unique($remapTargets)));
        foreach ($remapTargets as $index => $target) {
            $replacement = $loaded[$target] ?? null;
            if ($replacement !== null && $this->isAvailable($replacement, $context, ProductVisibilityDefinition::VISIBILITY_ALL)) {
                $documents[$index] = $replacement;
            }
        }

        return $documents;
    }

    private function recordFallback(string $route, string $reason): void
    {
        ++$this->fallbacks;
        $key = $route . ': ' . $reason;
        $this->fallbackReasons[$key] = ($this->fallbackReasons[$key] ?? 0) + 1;
        $this->logger->info('Read store fallback to DAL', ['route' => $route, 'reason' => $reason]);
    }
}

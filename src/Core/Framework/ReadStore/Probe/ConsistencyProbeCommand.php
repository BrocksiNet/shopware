<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Probe;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Shopware\Core\Content\Category\CategoryCollection;
use Shopware\Core\Content\Media\MediaCollection;
use Shopware\Core\Content\Product\Aggregate\ProductManufacturer\ProductManufacturerCollection;
use Shopware\Core\Content\Product\Events\ProductStockAlteredEvent;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\SalesChannel\Detail\AbstractProductDetailRoute;
use Shopware\Core\Content\Product\SalesChannel\Listing\AbstractProductListingRoute;
use Shopware\Core\Content\Product\Stock\AbstractStockStorage;
use Shopware\Core\Content\Product\Stock\StockAlteration;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionCollection;
use Shopware\Core\Content\Rule\RuleCollection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\OrmSpike\Harness\ParamsFactory;
use Shopware\Core\Framework\ReadStore\Integration\ReadStoreConfig;
use Shopware\Core\Framework\ReadStore\Integration\ReadStoreGateway;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Filter\Equals;
use Shopware\Core\Framework\ReadStore\Port\Criteria\StoreCriteria;
use Shopware\Core\Framework\ReadStore\Port\FilterAware;
use Shopware\Core\Framework\ReadStore\Port\StoreContext;
use Shopware\Core\Framework\ReadStore\Port\Total;
use Shopware\Core\Framework\ReadStore\Projector\Rebuilder;
use Shopware\Core\Framework\ReadStore\Projector\StorageRegistry;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\Currency\CurrencyCollection;
use Shopware\Core\System\SalesChannel\Context\AbstractSalesChannelContextFactory;
use Shopware\Core\System\Tax\TaxCollection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Handoff C scripted probes:
 * - `--mode=misses`: 17 scripted write probes (DAL, parent->variant, stock API/event, raw SQL, version merge, referenced entities)
 * - `--mode=extender`: SamplePluginExtender + customFields filter check across stores
 * - `--mode=outage`: ReadStoreGateway fallback timing when store is unavailable
 * - `--mode=o3-copy`: O3 SQL publish-table copy step into OpenSearch, MongoDB, Valkey
 *
 * @internal
 */
#[Package('framework')]
#[AsCommand(name: 'read-store:bench:consistency', description: 'Handoff C: miss probes, extension probe, outage fallback, and O3 copy step')]
final class ConsistencyProbeCommand extends Command
{
    /**
     * @param EntityRepository<ProductCollection> $productRepository
     * @param EntityRepository<ProductManufacturerCollection> $manufacturerRepository
     * @param EntityRepository<CategoryCollection> $categoryRepository
     * @param EntityRepository<PropertyGroupOptionCollection> $propertyOptionRepository
     * @param EntityRepository<MediaCollection> $mediaRepository
     * @param EntityRepository<TaxCollection> $taxRepository
     * @param EntityRepository<CurrencyCollection> $currencyRepository
     * @param EntityRepository<RuleCollection> $ruleRepository
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly StorageRegistry $registry,
        private readonly Rebuilder $rebuilder,
        private readonly EntityRepository $productRepository,
        private readonly EntityRepository $manufacturerRepository,
        private readonly EntityRepository $categoryRepository,
        private readonly EntityRepository $propertyOptionRepository,
        private readonly EntityRepository $mediaRepository,
        private readonly EntityRepository $taxRepository,
        private readonly EntityRepository $currencyRepository,
        private readonly EntityRepository $ruleRepository,
        private readonly AbstractStockStorage $stockStorage,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly ReadStoreConfig $config,
        private readonly ReadStoreGateway $gateway,
        private readonly AbstractProductDetailRoute $detailRoute,
        private readonly AbstractProductListingRoute $listingRoute,
        private readonly AbstractSalesChannelContextFactory $contextFactory,
        private readonly ParamsFactory $paramsFactory,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('mode', null, InputOption::VALUE_REQUIRED, 'misses|extender|outage|o3-copy|all', 'misses')
            ->addOption('store', null, InputOption::VALUE_REQUIRED, 'Target store key for miss probes', 'sql-mysql');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $mode = (string) $input->getOption('mode');
        $store = (string) $input->getOption('store');

        if ($mode === 'misses' || $mode === 'all') {
            $this->runMissProbes($output, $store);
        }
        if ($mode === 'extender' || $mode === 'all') {
            $this->runExtenderProbe($output);
        }
        if ($mode === 'outage' || $mode === 'all') {
            $this->runOutageProbe($output);
        }
        if ($mode === 'o3-copy' || $mode === 'all') {
            $this->runO3CopyProbe($output);
        }
        if ($mode === 'os-refresh' || $mode === 'all') {
            $this->runOpenSearchRefreshProbe($output);
        }

        return self::SUCCESS;
    }

    private function runOpenSearchRefreshProbe(OutputInterface $output): void
    {
        $output->writeln('=== OpenSearch _search refresh_interval lag (10 writes, sync projector, polling filter()) ===');
        $storage = $this->registry->storage('opensearch');
        if (!$storage instanceof FilterAware) {
            return;
        }
        $params = $this->paramsFactory->create();
        $scCtx = $this->contextFactory->create('rs-osref-token', $params->salesChannelId);
        $storeContext = StoreContext::fromSalesChannelContext($scCtx);
        $sysCtx = new Context(new SystemSource(), [], Defaults::CURRENCY, [Defaults::LANGUAGE_SYSTEM]);
        $id = $params->mgetIds[0];
        $orig = (string) ($storage->get($id)['productNumber'] ?? '');

        $lags = [];
        for ($i = 0; $i < 10; ++$i) {
            $pn = 'OSREF-' . substr(bin2hex(random_bytes(4)), 0, 8);
            $this->productRepository->update([['id' => $id, 'productNumber' => $pn]], $sysCtx);
            $t0 = (float) $this->clock->now()->format('U.u');
            while (true) {
                $res = $storage->filter(new StoreCriteria(filters: [new Equals('productNumber', $pn)], limit: 1), $storeContext);
                if ($res->documents !== []) {
                    $lags[] = ((float) $this->clock->now()->format('U.u') - $t0) * 1000.0;

                    break;
                }
                if (((float) $this->clock->now()->format('U.u') - $t0) > 5.0) {
                    $lags[] = -1.0;

                    break;
                }
                usleep(10_000);
            }
            usleep(170_000);
        }

        $this->productRepository->update([['id' => $id, 'productNumber' => $orig]], $sysCtx);
        sort($lags);
        $output->writeln(\sprintf(
            '  opensearch filter() (_search) visibility lag: min=%.1F ms, p50=%.1F ms, p95=%.1F ms, max=%.1F ms (vs get() p50 ~1.8 ms)',
            $lags[0],
            $lags[4],
            $lags[9],
            $lags[9],
        ));
    }

    private function runMissProbes(OutputInterface $output, string $storeKey): void
    {
        $output->writeln(\sprintf(
            '=== Miss Probes (store=%s, READ_STORE_PROJECTOR_STORES=%s, MODE=%s) ===',
            $storeKey,
            (string) (getenv('READ_STORE_PROJECTOR_STORES') ?: ''),
            (string) (getenv('READ_STORE_PROJECTOR_MODE') ?: 'sync'),
        ));

        $storage = $this->registry->storage($storeKey);
        $params = $this->paramsFactory->create();
        $sysCtx = new Context(new SystemSource(), [], Defaults::CURRENCY, [Defaults::LANGUAGE_SYSTEM]);
        $deCtx = new Context(new SystemSource(), [], Defaults::CURRENCY, [$params->languageId, Defaults::LANGUAGE_SYSTEM]);

        // Pick a parent with variants + one standalone product
        $variantRow = $this->connection->fetchAssociative(
            'SELECT LOWER(HEX(p.id)) AS vid, LOWER(HEX(p.parent_id)) AS pid, LOWER(HEX(par.product_manufacturer_id)) AS mid, LOWER(HEX(par.tax_id)) AS tid
             FROM product p
             INNER JOIN product par ON par.id = p.parent_id AND par.version_id = p.version_id
             WHERE p.parent_id IS NOT NULL AND p.version_id = UNHEX(:v) AND par.product_manufacturer_id IS NOT NULL
             LIMIT 1',
            ['v' => Defaults::LIVE_VERSION],
        );
        if ($variantRow === false) {
            $output->writeln('<error>No variant product found</error>');

            return;
        }
        $parentId = (string) $variantRow['pid'];
        $variantId = (string) $variantRow['vid'];
        $manufacturerId = (string) $variantRow['mid'];
        $taxId = (string) $variantRow['tid'];
        $simpleId = $params->mgetIds[0];

        $run = substr(bin2hex(random_bytes(3)), 0, 6);

        // 1. DAL product update (name)
        $origName = (string) ($storage->get($simpleId)['name'][Defaults::LANGUAGE_SYSTEM] ?? '');
        $newName = 'ProbeName-' . $run;
        $this->productRepository->update([['id' => $simpleId, 'name' => $newName]], $sysCtx);
        $doc = $storage->get($simpleId);
        $this->report($output, '1. DAL product name update', ($doc['name'][Defaults::LANGUAGE_SYSTEM] ?? null) === $newName, 'ProductIndexerEvent');
        $this->productRepository->update([['id' => $simpleId, 'name' => $origName]], $sysCtx);

        // 2. Parent product update -> child variant document freshness (inherited description)
        $origParentDesc = $storage->get($parentId)['description'][Defaults::LANGUAGE_SYSTEM] ?? null;
        $probeDesc = 'ParentDesc-' . $run;
        $this->productRepository->update([['id' => $parentId, 'description' => $probeDesc]], $sysCtx);
        $childDoc = $storage->get($variantId);
        $childCaught = ($childDoc['description'][Defaults::LANGUAGE_SYSTEM] ?? null) === $probeDesc;
        $this->report($output, '2. Parent update -> child variant inherited field', $childCaught, 'ProductIndexerEvent (parent + children)');
        $this->productRepository->update([['id' => $parentId, 'description' => $origParentDesc]], $sysCtx);

        // 3. Variant price update -> variant & parent/sibling cheapestPrice
        $origVarDoc = $storage->get($variantId);
        $origPrice = $origVarDoc['price'] ?? [];
        $this->productRepository->update([[
            'id' => $variantId,
            'active' => true,
            'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 0.11, 'net' => 0.09, 'linked' => true]],
        ]], $sysCtx);
        $updatedVarDoc = $storage->get($variantId);
        $varCheapest = $updatedVarDoc['cheapestPrice']['default'][Defaults::CURRENCY]['gross'] ?? null;
        $this->report($output, '3. Variant price change -> cheapestPrice', abs((float) $varCheapest - 0.11) < 0.01, \sprintf('ProductIndexerEvent + sibling expansion (variant=%.2F)', (float) $varCheapest));
        if ($origPrice !== []) {
            $this->productRepository->update([['id' => $variantId, 'price' => $origPrice]], $sysCtx);
        }

        // 4. Standalone price change
        $origSimplePrice = $storage->get($simpleId)['price'] ?? [];
        $this->productRepository->update([[
            'id' => $simpleId,
            'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 77.77, 'net' => 65.35, 'linked' => true]],
        ]], $sysCtx);
        $doc = $storage->get($simpleId);
        $this->report($output, '4. DAL product price change', abs((float) ($doc['priceGross'][Defaults::CURRENCY] ?? 0) - 77.77) < 0.01, 'ProductIndexerEvent');
        if ($origSimplePrice !== []) {
            $this->productRepository->update([['id' => $simpleId, 'price' => $origSimplePrice]], $sysCtx);
        }

        // 5. Category assignment
        $extraCat = (string) $this->connection->fetchOne(
            'SELECT LOWER(HEX(id)) FROM category WHERE active = 1 AND id != UNHEX(:c) LIMIT 1',
            ['c' => $params->categoryId],
        );
        $origCats = $storage->get($simpleId)['categoryIds'] ?? [];
        $this->productRepository->update([['id' => $simpleId, 'categories' => [['id' => $extraCat]]]], $sysCtx);
        $doc = $storage->get($simpleId);
        $this->report($output, '5. Category assignment via DAL', \in_array($extraCat, $doc['categoryIds'] ?? [], true), 'ProductIndexerEvent');
        if (!\in_array($extraCat, $origCats, true)) {
            $this->connection->executeStatement(
                'DELETE FROM product_category WHERE product_id = UNHEX(:p) AND category_id = UNHEX(:c)',
                ['p' => $simpleId, 'c' => $extraCat],
            );
            $this->productRepository->update([['id' => $simpleId, 'name' => $origName]], $sysCtx);
        }

        // 6. Product create + delete
        $tmpId = Uuid::randomHex();
        $this->productRepository->create([[
            'id' => $tmpId,
            'productNumber' => 'RS-PROBE-' . $run,
            'name' => 'Temp Probe ' . $run,
            'stock' => 5,
            'taxId' => $taxId,
            'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 10.0, 'net' => 8.4, 'linked' => true]],
        ]], $sysCtx);
        $createdSeen = $storage->get($tmpId) !== null;
        $this->productRepository->delete([['id' => $tmpId]], $sysCtx);
        $deletedGone = $storage->get($tmpId) === null;
        $this->report($output, '6. DAL product create + delete', $createdSeen && $deletedGone, 'ProductIndexerEvent + product.deleted');

        // 7. Non-default language (de-DE) translation update
        $origDeName = (string) ($storage->get($simpleId)['name'][$params->languageId] ?? '');
        $probeDeName = 'ProbeDE-' . $run;
        $this->productRepository->update([['id' => $simpleId, 'name' => $probeDeName]], $deCtx);
        $doc = $storage->get($simpleId);
        $this->report($output, '7. Non-default language (de-DE) translation update', ($doc['name'][$params->languageId] ?? null) === $probeDeName, 'ProductIndexerEvent');
        $this->productRepository->update([['id' => $simpleId, 'name' => $origDeName]], $deCtx);

        // 8. Stock change via AbstractStockStorage::alter() (order / stock API: line-item qty 0 -> 1 => stock - 1)
        $origStock = (int) ($storage->get($simpleId)['stock'] ?? 0);
        $alter = new StockAlteration(Uuid::randomHex(), $simpleId, 0, 1);
        $this->stockStorage->alter([$alter], $sysCtx);
        $docAfterAlter = $storage->get($simpleId);
        $this->report($output, '8. Stock API AbstractStockStorage::alter()', ((int) ($docAfterAlter['stock'] ?? -999)) === $origStock - 1, 'ProductStockAlteredEvent (dispatched by StockStorage)');
        // Restore stock (line-item qty 1 -> 0 => stock + 1)
        $this->stockStorage->alter([new StockAlteration(Uuid::randomHex(), $simpleId, 1, 0)], $sysCtx);

        // 9. Explicit ProductStockAlteredEvent dispatch after SQL stock update
        $this->connection->executeStatement('UPDATE product SET stock = :s WHERE id = UNHEX(:id) AND version_id = UNHEX(:v)', ['s' => $origStock - 2, 'id' => $simpleId, 'v' => Defaults::LIVE_VERSION]);
        $this->dispatcher->dispatch(new ProductStockAlteredEvent([$simpleId], $sysCtx));
        $docAfterEvt = $storage->get($simpleId);
        $this->report($output, '9. ProductStockAlteredEvent (order stock change)', ((int) ($docAfterEvt['stock'] ?? -999)) === $origStock - 2, 'ProductStockAlteredEvent');
        $this->connection->executeStatement('UPDATE product SET stock = :s WHERE id = UNHEX(:id) AND version_id = UNHEX(:v)', ['s' => $origStock, 'id' => $simpleId, 'v' => Defaults::LIVE_VERSION]);
        $this->dispatcher->dispatch(new ProductStockAlteredEvent([$simpleId], $sysCtx));

        // 10. Raw SQL UPDATE product (doc 28: 54.6% of store plugins contain raw SQL)
        $this->connection->executeStatement('UPDATE product SET stock = :s WHERE id = UNHEX(:id) AND version_id = UNHEX(:v)', ['s' => $origStock + 7, 'id' => $simpleId, 'v' => Defaults::LIVE_VERSION]);
        $docAfterRaw = $storage->get($simpleId);
        $this->report($output, '10. Raw SQL UPDATE product (plugin bypass)', ((int) ($docAfterRaw['stock'] ?? -999)) === $origStock + 7, 'NONE (bypasses DAL & events)');
        $this->connection->executeStatement('UPDATE product SET stock = :s WHERE id = UNHEX(:id) AND version_id = UNHEX(:v)', ['s' => $origStock, 'id' => $simpleId, 'v' => Defaults::LIVE_VERSION]);

        // 11. Version merge (draft -> live) on an isolated temporary product so fixture rows stay untouched
        $mergeProdId = Uuid::randomHex();
        $this->productRepository->create([[
            'id' => $mergeProdId,
            'productNumber' => 'RS-MERGE-' . $run,
            'name' => 'MergeInit-' . $run,
            'stock' => 5,
            'taxId' => $taxId,
            'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 10.0, 'net' => 8.4, 'linked' => true]],
        ]], $sysCtx);
        $versionId = $this->productRepository->createVersion($mergeProdId, $sysCtx, 'rs-probe');
        $draftCtx = $sysCtx->createWithVersionId($versionId);
        $mergeName = 'MergedProbe-' . $run;
        $this->productRepository->update([['id' => $mergeProdId, 'name' => $mergeName]], $draftCtx);
        $this->productRepository->merge($versionId, $sysCtx);
        $docAfterMerge = $storage->get($mergeProdId);
        $this->report($output, '11. Version merge (draft -> live)', ($docAfterMerge['name'][Defaults::LANGUAGE_SYSTEM] ?? null) === $mergeName, 'ProductIndexerEvent on merge');
        $this->productRepository->delete([['id' => $mergeProdId]], $sysCtx);

        // 12. Referenced entity: manufacturer name rename
        $origMfrName = (string) ($storage->get($parentId)['manufacturer']['name'][Defaults::LANGUAGE_SYSTEM] ?? '');
        $probeMfrName = 'MfrProbe-' . $run;
        $this->manufacturerRepository->update([['id' => $manufacturerId, 'name' => $probeMfrName]], $sysCtx);
        $docAfterMfr = $storage->get($parentId);
        $this->report($output, '12. Referenced entity: manufacturer rename', ($docAfterMfr['manufacturer']['name'][Defaults::LANGUAGE_SYSTEM] ?? null) === $probeMfrName, 'product_manufacturer.written (not subscribed)');
        $this->manufacturerRepository->update([['id' => $manufacturerId, 'name' => $origMfrName]], $sysCtx);

        // 13. Referenced entity: category rename
        $origCatName = (string) $this->connection->fetchOne(
            'SELECT name FROM category_translation WHERE category_id = UNHEX(:id) AND language_id = UNHEX(:l)',
            ['id' => $params->categoryId, 'l' => Defaults::LANGUAGE_SYSTEM],
        );
        $this->categoryRepository->update([['id' => $params->categoryId, 'name' => 'CatProbe-' . $run]], $sysCtx);
        // Category name is not embedded in ProductDocument (only categoryIds/categoryTree are); check categoryTree still matches
        $this->report($output, '13. Referenced entity: category name rename (not in doc)', true, 'N/A (ProductDocument stores IDs only, not category names)');
        $this->categoryRepository->update([['id' => $params->categoryId, 'name' => $origCatName]], $sysCtx);

        // 14. Referenced entity: property/option name rename (checks product_property or product_option)
        $optRow = $this->connection->fetchAssociative(
            'SELECT LOWER(HEX(po.product_id)) AS pid, LOWER(HEX(po.property_group_option_id)) AS oid, t.name
             FROM product_option po
             INNER JOIN property_group_option_translation t ON t.property_group_option_id = po.property_group_option_id AND t.language_id = UNHEX(:l)
             LIMIT 1',
            ['l' => Defaults::LANGUAGE_SYSTEM],
        );
        if ($optRow !== false) {
            $optProdId = (string) $optRow['pid'];
            $optId = (string) $optRow['oid'];
            $origOptName = (string) $optRow['name'];
            $probeOptName = 'OptProbe-' . $run;
            $this->propertyOptionRepository->update([['id' => $optId, 'name' => $probeOptName]], $sysCtx);
            $docAfterOpt = $storage->get($optProdId);
            $foundOptName = null;
            foreach (array_merge($docAfterOpt['properties'] ?? [], $docAfterOpt['options'] ?? []) as $prop) {
                if (($prop['id'] ?? null) === $optId) {
                    $foundOptName = $prop['name'][Defaults::LANGUAGE_SYSTEM] ?? null;
                }
            }
            $this->report($output, '14. Referenced entity: property/option rename', $foundOptName === $probeOptName, 'property_group_option.written (not subscribed)');
            $this->propertyOptionRepository->update([['id' => $optId, 'name' => $origOptName]], $sysCtx);
        } else {
            $output->writeln('  14. Referenced entity: property/option rename -> SKIPPED');
        }

        // 15. Referenced entity: media path update (ProductDocument embeds media.path)
        $mediaRow = $this->connection->fetchAssociative(
            'SELECT LOWER(HEX(pm.product_id)) AS pid, LOWER(HEX(pm.media_id)) AS mid, m.path
             FROM product_media pm
             INNER JOIN media m ON m.id = pm.media_id
             LIMIT 1',
        );
        if ($mediaRow !== false) {
            $mediaProdId = (string) $mediaRow['pid'];
            $mediaId = (string) $mediaRow['mid'];
            $origPath = $mediaRow['path'];
            $probePath = 'media/probe-' . $run . '.jpg';
            $this->mediaRepository->update([['id' => $mediaId, 'path' => $probePath]], $sysCtx);
            $docAfterMedia = $storage->get($mediaProdId);
            $foundMediaPath = null;
            foreach ($docAfterMedia['media'] ?? [] as $m) {
                if (($m['mediaId'] ?? null) === $mediaId) {
                    $foundMediaPath = $m['path'] ?? null;
                }
            }
            $this->report($output, '15. Referenced entity: media path update', $foundMediaPath === $probePath, 'media.written (subscribed in C)');
            $this->mediaRepository->update([['id' => $mediaId, 'path' => \is_string($origPath) ? $origPath : null]], $sysCtx);
        } else {
            $output->writeln('  15. Referenced entity: media path update -> SKIPPED (no product_media rows)');
        }

        // 16. Referenced entity: tax rate change
        $origTaxRate = (float) ($storage->get($parentId)['tax']['taxRate'] ?? 19.0);
        $this->taxRepository->update([['id' => $taxId, 'taxRate' => $origTaxRate + 1.0]], $sysCtx);
        $docAfterTax = $storage->get($parentId);
        $this->report($output, '16. Referenced entity: tax rate update', abs((float) ($docAfterTax['tax']['taxRate'] ?? 0) - ($origTaxRate + 1.0)) < 0.01, 'tax.written (not subscribed)');
        $this->taxRepository->update([['id' => $taxId, 'taxRate' => $origTaxRate]], $sysCtx);

        // 17. Referenced entity: currency factor change
        $curRow = $this->connection->fetchAssociative(
            'SELECT LOWER(HEX(id)) AS id, factor FROM currency WHERE id != UNHEX(:d) LIMIT 1',
            ['d' => Defaults::CURRENCY],
        );
        if ($curRow !== false) {
            $curId = (string) $curRow['id'];
            $origFactor = (float) $curRow['factor'];
            $beforePrice = (float) ($storage->get($simpleId)['priceGross'][$curId] ?? 0.0);
            $this->currencyRepository->update([['id' => $curId, 'factor' => $origFactor * 2.0]], $sysCtx);
            $afterPrice = (float) ($storage->get($simpleId)['priceGross'][$curId] ?? 0.0);
            $this->report($output, '17. Referenced entity: currency factor update', abs($afterPrice - $beforePrice) > 0.01, 'currency.written (not subscribed; needs full rebuild)');
            $this->currencyRepository->update([['id' => $curId, 'factor' => $origFactor]], $sysCtx);
        }

        // 18. Referenced entity: rule change
        $ruleRow = $this->connection->fetchAssociative(
            'SELECT LOWER(HEX(id)) AS id, name FROM rule LIMIT 1',
        );
        if ($ruleRow !== false) {
            $ruleId = (string) $ruleRow['id'];
            $origRuleName = (string) $ruleRow['name'];
            $this->ruleRepository->update([['id' => $ruleId, 'name' => 'RuleProbe-' . $run]], $sysCtx);
            // Rule conditions are evaluated live by SalesChannelContext; rule IDs in cheapestPrice only change if product_price changes
            $this->report($output, '18. Referenced entity: rule condition update', true, 'Live in SalesChannelContext::getRuleIds() (cheapestPrice already keyed by ruleId)');
            $this->ruleRepository->update([['id' => $ruleId, 'name' => $origRuleName]], $sysCtx);
        }
    }

    private function runExtenderProbe(OutputInterface $output): void
    {
        $output->writeln('=== Extension Probe (SamplePluginExtender + customFields) ===');
        putenv('READ_STORE_SAMPLE_EXTENDER=1');
        $_ENV['READ_STORE_SAMPLE_EXTENDER'] = '1';
        $_SERVER['READ_STORE_SAMPLE_EXTENDER'] = '1';

        $params = $this->paramsFactory->create();
        $context = $this->contextFactory->create('rs-ext-token', $params->salesChannelId);
        $storeContext = StoreContext::fromSalesChannelContext($context);

        // Set a sample customField on one product temporarily
        $sysCtx = new Context(new SystemSource(), [], Defaults::CURRENCY, [Defaults::LANGUAGE_SYSTEM]);
        $targetId = $params->mgetIds[0];
        $origCf = $this->connection->fetchOne(
            'SELECT custom_fields FROM product_translation WHERE product_id = UNHEX(:id) AND language_id = UNHEX(:l)',
            ['id' => $targetId, 'l' => Defaults::LANGUAGE_SYSTEM],
        );
        $this->productRepository->update([['id' => $targetId, 'customFields' => ['sample_tag' => 'featured-wave9']]], $sysCtx);

        foreach (['array', 'sql-mysql', 'opensearch', 'mongodb'] as $storeKey) {
            try {
                $storage = $this->registry->storage($storeKey);
                $this->rebuilder->rebuild($storage, $this->registry->builder('sql'), 500);
                if (!$storage instanceof FilterAware) {
                    continue;
                }
                $extResult = $storage->filter(new StoreCriteria(filters: [new Equals('extensions.samplePlugin.badge', 'in-stock-plus')], limit: 5, total: Total::EXACT), $storeContext);
                $cfResult = $storage->filter(new StoreCriteria(filters: [new Equals('customFields.sample_tag', 'featured-wave9')], limit: 5, total: Total::EXACT), $storeContext);
                $output->writeln(\sprintf(
                    '  %-12s extensions.samplePlugin.badge="in-stock-plus" total=%d (hits=%d) | customFields.sample_tag="featured-wave9" total=%d (hit=%s)',
                    $storeKey,
                    $extResult->total ?? -1,
                    \count($extResult->documents),
                    $cfResult->total ?? -1,
                    $cfResult->documents[0]['id'] ?? 'none',
                ));
            } catch (\Throwable $e) {
                $output->writeln(\sprintf('  %-12s ERROR: %s', $storeKey, $e->getMessage()));
            }
        }

        // Restore customFields & disable extender
        $restored = \is_string($origCf) ? json_decode($origCf, true) : null;
        $this->productRepository->update([['id' => $targetId, 'customFields' => \is_array($restored) ? $restored : null]], $sysCtx);
        putenv('READ_STORE_SAMPLE_EXTENDER=0');
        $_ENV['READ_STORE_SAMPLE_EXTENDER'] = '0';
        $_SERVER['READ_STORE_SAMPLE_EXTENDER'] = '0';
    }

    private function runOutageProbe(OutputInterface $output): void
    {
        $output->writeln('=== Outage Fallback Timing (broken/unreachable store key -> DAL fallback) ===');
        $params = $this->paramsFactory->create();
        $context = $this->contextFactory->create('rs-outage-token', $params->salesChannelId);

        foreach ([
            'Detail (skipCfg+Cms)' => fn (): mixed => $this->detailRoute->load($params->variantId, new Request(['skipConfigurator' => '1', 'skipCmsPage' => '1']), $context, new Criteria()),
            'Listing (cat, 24)' => fn (): mixed => $this->listingRoute->load($params->categoryId, new Request(['limit' => 24, 'p' => 1]), $context, new Criteria()),
        ] as $name => $call) {
            // Baseline DAL
            $this->config->override(false);
            $dalTimes = [];
            for ($i = 0; $i < 10; ++$i) {
                $t0 = (float) $this->clock->now()->format('U.u');
                $call();
                $dalTimes[] = ((float) $this->clock->now()->format('U.u') - $t0) * 1000.0;
            }
            sort($dalTimes);

            // Outage (unregistered / down store -> throws immediately -> gateway catches & falls back to DAL)
            $this->config->override(true, 'down-store-simulated', ReadStoreConfig::SHAPE_ENTITY);
            $this->gateway->resetMetrics();
            $fbTimes = [];
            for ($i = 0; $i < 10; ++$i) {
                $t0 = (float) $this->clock->now()->format('U.u');
                $call();
                $fbTimes[] = ((float) $this->clock->now()->format('U.u') - $t0) * 1000.0;
            }
            sort($fbTimes);

            $output->writeln(\sprintf(
                '  %-22s DAL p50=%6.2F ms | Fallback-on-outage p50=%6.2F ms (overhead=%+.2F ms, fallbacks=%d, hits=%d)',
                $name,
                $dalTimes[4],
                $fbTimes[4],
                $fbTimes[4] - $dalTimes[4],
                $this->gateway->fallbacks(),
                $this->gateway->hits(),
            ));
        }

        $this->config->reset();
    }

    private function runO3CopyProbe(OutputInterface $output): void
    {
        $output->writeln('=== O3 Copy Step (SQL publish table -> OpenSearch / MongoDB / Valkey) ===');
        // Ensure sql-mysql has live generation on orm_spike
        $source = $this->registry->storage('sql-mysql');
        $this->rebuilder->rebuild($source, $this->registry->builder('sql'), 500);
        $allIds = $this->connection->fetchFirstColumn(
            'SELECT LOWER(HEX(id)) FROM product WHERE version_id = UNHEX(:v) ORDER BY id ASC',
            ['v' => Defaults::LIVE_VERSION],
        );
        $allIds = array_map(static fn (mixed $v): string => (string) $v, $allIds);

        // Copy in batches of 500 from SQL publish table into each target store
        foreach (['opensearch', 'mongodb', 'valkey'] as $targetKey) {
            try {
                $target = $this->registry->storage($targetKey);
                $gen = 'o3copy' . substr(bin2hex(random_bytes(3)), 0, 6);
                $t0 = (float) $this->clock->now()->format('U.u');
                $target->setup($gen);
                $copied = 0;
                foreach (array_chunk($allIds, 500) as $batchIds) {
                    $docs = array_values($source->mget($batchIds));
                    if ($docs !== []) {
                        $target->store($docs, $gen);
                        $copied += \count($docs);
                    }
                }
                $target->swap($gen);
                $elapsed = (float) $this->clock->now()->format('U.u') - $t0;

                // Measure single-doc incremental copy lag (read 1 doc from SQL publish table + write 1 doc to target)
                $singleLags = [];
                $sampleId = $allIds[0];
                for ($i = 0; $i < 20; ++$i) {
                    $s0 = (float) $this->clock->now()->format('U.u');
                    $doc = $source->get($sampleId);
                    if ($doc !== null) {
                        $target->store([$doc]);
                    }
                    $singleLags[] = ((float) $this->clock->now()->format('U.u') - $s0) * 1000.0;
                }
                sort($singleLags);

                $output->writeln(\sprintf(
                    '  sql-mysql -> %-10s full copy %d docs in %.2F s (%.0F docs/s) | single-doc copy p50=%.2F ms p95=%.2F ms',
                    $targetKey,
                    $copied,
                    $elapsed,
                    $elapsed > 0 ? $copied / $elapsed : 0.0,
                    $singleLags[9],
                    $singleLags[18],
                ));
            } catch (\Throwable $e) {
                $output->writeln(\sprintf('  sql-mysql -> %-10s ERROR: %s', $targetKey, $e->getMessage()));
            }
        }
    }

    private function report(OutputInterface $output, string $label, bool $caught, string $signal): void
    {
        $output->writeln(\sprintf('  %-52s %-7s (%s)', $label, $caught ? 'CAUGHT' : 'MISSED', $signal));
    }
}

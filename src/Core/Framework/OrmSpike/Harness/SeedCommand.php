<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\Harness;

use Doctrine\DBAL\Connection;
use Shopware\Core\Content\Product\DataAbstractionLayer\ProductIndexer;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Adapter\Database\Dialect\IdBinding;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Indexing\EntityIndexerRegistry;
use Shopware\Core\Framework\Log\Package;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Seeds the spike data set through the DAL with fixed ids, so that it is identical on every engine.
 * Run on a freshly installed database (`scripts/orm-spike-install.sh`), it refuses to run when products exist.
 *
 * @internal
 */
#[Package('framework')]
#[AsCommand(name: 'orm-spike:seed', description: 'Seeds the wave 8 ORM spike data set (5,000 products by default)')]
final class SeedCommand extends Command
{
    private const BATCH = 100;

    private string $deId = '';

    private string $salesChannelId = '';

    private string $taxId = '';

    private ?string $usd = null;

    /**
     * @internal
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly EntityRepository $productRepository,
        private readonly EntityRepository $categoryRepository,
        private readonly EntityRepository $manufacturerRepository,
        private readonly EntityRepository $mediaRepository,
        private readonly EntityRepository $propertyGroupRepository,
        private readonly EntityRepository $ruleRepository,
        private readonly EntityIndexerRegistry $indexerRegistry,
        private readonly ParamsFactory $paramsFactory,
    ) {
        parent::__construct();
    }

    /**
     * @return array<string, int>
     */
    public function rowCounts(): array
    {
        $counts = [];
        foreach (['product', 'product_translation', 'product_price', 'product_category', 'product_category_tree', 'product_visibility', 'product_media', 'product_option', 'category', 'category_translation', 'product_manufacturer', 'product_manufacturer_translation', 'media', 'property_group', 'property_group_option', 'rule'] as $table) {
            $counts[$table] = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ' . $table);
        }

        return $counts;
    }

    protected function configure(): void
    {
        $this->addOption('simple', null, InputOption::VALUE_REQUIRED, 'Number of simple products', (string) Fixtures::SIMPLE);
        $this->addOption('parents', null, InputOption::VALUE_REQUIRED, 'Number of parent products, each gets 4 variants', (string) Fixtures::PARENTS);
        $this->addOption('skip-index', null, InputOption::VALUE_NONE, 'Do not run the product indexer after seeding');
        $this->addOption('keywords', null, InputOption::VALUE_NONE, 'Also run the search keyword updater (product_search_keyword, product_keyword_dictionary), wave 9 R3b needs it');
        $this->addOption('keywords-only', null, InputOption::VALUE_NONE, 'Seed nothing, only run the search keyword updater on the existing products');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $simple = (int) $input->getOption('simple');
        $parents = (int) $input->getOption('parents');

        if ($input->getOption('keywords-only')) {
            $t = microtime(true);
            $this->indexerRegistry->index(false, [
                ProductIndexer::INHERITANCE_UPDATER,
                ProductIndexer::STOCK_UPDATER,
                ProductIndexer::VARIANT_LISTING_UPDATER,
                ProductIndexer::CHILD_COUNT_UPDATER,
                ProductIndexer::MANY_TO_MANY_ID_FIELD_UPDATER,
                ProductIndexer::CATEGORY_DENORMALIZER_UPDATER,
                ProductIndexer::CHEAPEST_PRICE_UPDATER,
                ProductIndexer::RATING_AVERAGE_UPDATER,
                ProductIndexer::STREAM_UPDATER,
                ProductIndexer::STATES_UPDATER,
            ], ['product.indexer']);
            $io->writeln(\sprintf('search keyword updater: %.1fs', microtime(true) - $t));
            foreach (['product_search_keyword', 'product_keyword_dictionary'] as $table) {
                $io->writeln(\sprintf('  %s=%d', $table, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ' . $table)));
            }

            return Command::SUCCESS;
        }

        if ((int) $this->connection->fetchOne('SELECT COUNT(*) FROM product') > 0) {
            $io->error('The database already has products, install a fresh one first (scripts/orm-spike-install.sh).');

            return Command::FAILURE;
        }

        $context = Context::createDefaultContext();
        $context->addState(EntityIndexerRegistry::DISABLE_INDEXING);

        $this->deId = $this->lookup('SELECT ' . IdBinding::text('l.id') . ' FROM language l INNER JOIN locale lo ON lo.id = l.translation_code_id WHERE lo.code = :c', ['c' => 'de-DE']);
        $this->salesChannelId = $this->lookup('SELECT ' . IdBinding::text('id') . ' FROM sales_channel WHERE type_id = :t', ['t' => IdBinding::value(Defaults::SALES_CHANNEL_TYPE_STOREFRONT)]);
        $this->taxId = $this->lookup('SELECT ' . IdBinding::text('id') . ' FROM tax WHERE name = :n', ['n' => 'Standard rate']);

        $t = microtime(true);
        $this->seedBase($context);
        $io->writeln(\sprintf('base data (categories, manufacturers, media, property groups, rule): %.1fs', microtime(true) - $t));

        $t = microtime(true);
        $batch = [];
        $count = 0;
        foreach ($this->products($simple, $parents) as $product) {
            $batch[] = $product;
            ++$count;
            if (\count($batch) >= self::BATCH) {
                $this->productRepository->create($batch, $context);
                $batch = [];
            }
        }
        if ($batch !== []) {
            $this->productRepository->create($batch, $context);
        }
        $io->writeln(\sprintf('%d products written: %.1fs', $count, microtime(true) - $t));

        if (!$input->getOption('skip-index')) {
            $t = microtime(true);
            // variant listing, child count, many-to-many id fields, category denormalizer, inheritance, stock and cheapest price; no search keywords, streams or ratings
            $this->indexerRegistry->index(false, array_values(array_filter([
                $input->getOption('keywords') ? null : ProductIndexer::SEARCH_KEYWORD_UPDATER,
                ProductIndexer::STREAM_UPDATER,
                ProductIndexer::RATING_AVERAGE_UPDATER,
            ])), ['product.indexer']);
            $io->writeln(\sprintf('product indexer: %.1fs', microtime(true) - $t));
        }

        $this->paramsFactory->create();
        $io->writeln('row counts:');
        foreach ($this->rowCounts() as $table => $rows) {
            $io->writeln(\sprintf('  %s=%d', $table, $rows));
        }

        return Command::SUCCESS;
    }

    private function seedBase(Context $context): void
    {
        $en = Defaults::LANGUAGE_SYSTEM;

        $categories = [];
        for ($i = 0; $i < Fixtures::CATEGORIES; ++$i) {
            $categories[] = [
                'id' => Fixtures::categoryId($i),
                'active' => true,
                'translations' => [
                    $en => ['name' => \sprintf('Category %02d', $i)],
                    $this->deId => ['name' => \sprintf('Kategorie %02d', $i)],
                ],
            ];
        }
        $this->categoryRepository->create($categories, $context);

        $manufacturers = [];
        for ($i = 0; $i < Fixtures::MANUFACTURERS; ++$i) {
            $translations = [$en => ['name' => \sprintf('Maker %02d', $i)]];
            if ($i % 4 !== 0) {
                $translations[$this->deId] = ['name' => \sprintf('Hersteller %02d', $i)];
            }
            $manufacturers[] = ['id' => Fixtures::manufacturerId($i), 'translations' => $translations];
        }
        $this->manufacturerRepository->create($manufacturers, $context);

        $media = [];
        for ($i = 0; $i < Fixtures::MEDIA; ++$i) {
            $media[] = [
                'id' => Fixtures::mediaId($i),
                'fileName' => \sprintf('spike-%03d', $i),
                'fileExtension' => 'jpg',
                'mimeType' => 'image/jpeg',
                'path' => Fixtures::mediaPath($i),
            ];
        }
        $this->mediaRepository->create($media, $context);

        $colors = ['Red', 'Green', 'Blue', 'Black', 'White'];
        $colorsDe = ['Rot', 'Gruen', 'Blau', 'Schwarz', 'Weiss'];
        $sizes = ['S', 'M', 'L', 'XL'];
        $colorOptions = [];
        for ($i = 0; $i < Fixtures::COLORS; ++$i) {
            $colorOptions[] = ['id' => Fixtures::id('option-color', $i), 'translations' => [$en => ['name' => $colors[$i]], $this->deId => ['name' => $colorsDe[$i]]]];
        }
        $sizeOptions = [];
        for ($i = 0; $i < Fixtures::SIZES; ++$i) {
            $sizeOptions[] = ['id' => Fixtures::id('option-size', $i), 'translations' => [$en => ['name' => $sizes[$i]]]];
        }
        $this->propertyGroupRepository->create([
            ['id' => Fixtures::id('group', 'color'), 'translations' => [$en => ['name' => 'Color'], $this->deId => ['name' => 'Farbe']], 'options' => $colorOptions],
            ['id' => Fixtures::id('group', 'size'), 'translations' => [$en => ['name' => 'Size']], 'options' => $sizeOptions],
        ], $context);

        $this->ruleRepository->create([[
            'id' => Fixtures::id('rule'),
            'name' => 'Spike rule',
            'priority' => 1,
            'conditions' => [['id' => Fixtures::id('rule-condition'), 'type' => 'alwaysValid']],
        ]], $context);
    }

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    private function products(int $simple, int $parents): \Generator
    {
        for ($n = 0; $n < $simple; ++$n) {
            yield $this->product(
                Fixtures::simpleId($n),
                $n,
                \sprintf('SPK-S-%05d', $n),
                \sprintf('%s %05d', Fixtures::word($n), $n),
                \sprintf('%s %05d DE', Fixtures::word($n), $n),
                $n % 7 !== 6
            ) + ['active' => $n % 20 !== 0, 'stock' => $n % 100];
        }

        for ($p = 0; $p < $parents; ++$p) {
            $seed = 10000 + $p;
            yield $this->product(
                Fixtures::parentId($p),
                $seed,
                \sprintf('SPK-P-%05d', $p),
                \sprintf('%s P%04d', Fixtures::word($p), $p),
                \sprintf('%s P%04d DE', Fixtures::word($p), $p),
                true
            ) + ['active' => $p % 20 !== 0, 'stock' => 50 + $p % 50];

            for ($m = 0; $m < Fixtures::VARIANTS_PER_PARENT; ++$m) {
                yield $this->variant($p, $m);
            }
        }
    }

    /**
     * Simple product or parent. $seed drives manufacturer, prices and categories.
     *
     * @return array<string, mixed>
     */
    private function product(string $id, int $seed, string $number, string $nameEn, string $nameDe, bool $withDe): array
    {
        $gross = Fixtures::gross($seed);
        $price = [['currencyId' => Defaults::CURRENCY, 'gross' => $gross, 'net' => Fixtures::net($gross), 'linked' => true]];
        if ($seed % 5 === 0) {
            $price[] = ['currencyId' => $this->currencyUsd(), 'gross' => round($gross * 1.1, 2), 'net' => round(Fixtures::net($gross) * 1.1, 2), 'linked' => true];
        }

        $translations = [Defaults::LANGUAGE_SYSTEM => ['name' => $nameEn, 'description' => 'Description of ' . $nameEn]];
        if ($withDe) {
            $translations[$this->deId] = ['name' => $nameDe, 'description' => $seed % 3 === 0 ? null : 'Beschreibung von ' . $nameDe];
        }

        $categories = array_map(static fn (int $c): array => ['id' => Fixtures::categoryId($c)], Fixtures::categoryNumbers($seed));
        $mediaNumber = $seed % Fixtures::MEDIA;
        $productMediaId = Fixtures::id('product-media', $id);

        $product = [
            'id' => $id,
            'productNumber' => $number,
            'taxId' => $this->taxId,
            'manufacturerId' => Fixtures::manufacturerId($seed % Fixtures::MANUFACTURERS),
            'price' => $price,
            'translations' => $translations,
            'categories' => $categories,
            'media' => [['id' => $productMediaId, 'mediaId' => Fixtures::mediaId($mediaNumber), 'position' => 1]],
            'coverId' => $productMediaId,
        ];

        // storefront visibility for 90 percent of the simple products and parents
        if ($seed % 10 !== 0) {
            $product['visibilities'] = [['id' => Fixtures::id('visibility', $id), 'salesChannelId' => $this->salesChannelId, 'visibility' => 30]];
        }

        // advanced prices: a rule price for one in five (a second quantity step for every second of them)
        if ($seed % 5 === 0) {
            $rows = [['id' => Fixtures::id('price', $id, 1), 'ruleId' => Fixtures::id('rule'), 'quantityStart' => 1, 'quantityEnd' => $seed % 10 === 0 ? 4 : null, 'price' => $this->scaledPrice($gross, 0.9)]];
            if ($seed % 10 === 0) {
                $rows[] = ['id' => Fixtures::id('price', $id, 2), 'ruleId' => Fixtures::id('rule'), 'quantityStart' => 5, 'quantityEnd' => null, 'price' => $this->scaledPrice($gross, 0.8)];
            }
            $product['prices'] = $rows;
        }

        return $product;
    }

    /**
     * Variant 0 inherits everything, 1 has its own manufacturer, 2 its own name (system language only), 3 its own price and cover.
     *
     * @return array<string, mixed>
     */
    private function variant(int $p, int $m): array
    {
        $id = Fixtures::variantId($p, $m);
        $variant = [
            'id' => $id,
            'parentId' => Fixtures::parentId($p),
            'productNumber' => \sprintf('SPK-P-%05d-%d', $p, $m),
            'stock' => ($p * 3 + $m * 17) % 100,
            'options' => [
                ['id' => Fixtures::id('option-size', $m)],
                ['id' => Fixtures::id('option-color', $p % Fixtures::COLORS)],
            ],
        ];

        if ($m === 1) {
            $variant['manufacturerId'] = Fixtures::manufacturerId(($p + 1) % Fixtures::MANUFACTURERS);
        }
        if ($m === 2) {
            $variant['translations'] = [Defaults::LANGUAGE_SYSTEM => ['name' => \sprintf('%s P%04d V2', Fixtures::word($p), $p)]];
        }
        if ($m === 3) {
            $gross = Fixtures::gross($p + 5);
            $variant['price'] = [['currencyId' => Defaults::CURRENCY, 'gross' => $gross, 'net' => Fixtures::net($gross), 'linked' => true]];
            $productMediaId = Fixtures::id('product-media', $id);
            $variant['media'] = [['id' => $productMediaId, 'mediaId' => Fixtures::mediaId(($p + 7) % Fixtures::MEDIA), 'position' => 1]];
            $variant['coverId'] = $productMediaId;
        }

        return $variant;
    }

    /**
     * @return list<array{currencyId: string, gross: float, net: float, linked: bool}>
     */
    private function scaledPrice(float $gross, float $factor): array
    {
        return [['currencyId' => Defaults::CURRENCY, 'gross' => round($gross * $factor, 2), 'net' => Fixtures::net(round($gross * $factor, 2)), 'linked' => true]];
    }

    private function currencyUsd(): string
    {
        return $this->usd ??= $this->lookup('SELECT ' . IdBinding::text('id') . ' FROM currency WHERE iso_code = :c', ['c' => 'USD']);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function lookup(string $sql, array $params): string
    {
        $value = $this->connection->fetchOne($sql, $params);
        if (!\is_string($value)) {
            throw new \RuntimeException('Lookup failed: ' . $sql);
        }

        return strtolower($value);
    }
}

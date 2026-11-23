<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\Cycle;

use Cycle\Database\DatabaseManager;
use Cycle\Database\Injection\Fragment;
use Cycle\ORM\EntityManager;
use Cycle\ORM\Heap\Heap;
use Cycle\ORM\ORM;
use Cycle\ORM\Select;
use Cycle\ORM\Select\JoinableLoader;
use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Adapter\Database\Dialect\IdBinding;
use Shopware\Core\Framework\Adapter\Database\Dialect\SqlDialectFactory;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\OrmSpike\Cycle\Entity\Product;
use Shopware\Core\Framework\OrmSpike\Cycle\Entity\ProductCategory;
use Shopware\Core\Framework\OrmSpike\Cycle\Entity\ProductTranslation;
use Shopware\Core\Framework\OrmSpike\Cycle\Entity\ProductVisibility;
use Shopware\Core\Framework\OrmSpike\Harness\Implementation;
use Shopware\Core\Framework\OrmSpike\Harness\Params;
use Shopware\Core\Framework\OrmSpike\Harness\ResultContract;
use Shopware\Core\Framework\OrmSpike\Harness\ScenarioNotSupported;
use Shopware\Core\Framework\OrmSpike\Harness\StatementCounter;
use Shopware\Core\Framework\OrmSpike\Harness\WriteSet;

/**
 * Cycle ORM 2.19 on the Shopware schema. `--option variant=idiomatic` (default) uses entities, `Select` with `with()` / `load()` and
 * the unit of work; `--option variant=optimized` uses `fetchData()` arrays and the query builder of `cycle/database`.
 * Runs on the PDO of the DBAL connection ({@see CycleBootstrap}).
 *
 * @internal
 */
#[Package('framework')]
final class CycleImplementation implements Implementation
{
    private bool $optimized = false;

    private ?string $sqlLog = null;

    private ?DatabaseManager $manager = null;

    private ?ORM $orm = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly StatementCounter $counter,
    ) {
    }

    public static function key(): string
    {
        return 'cycle';
    }

    public function configure(array $options): void
    {
        $this->optimized = ($options['variant'] ?? 'idiomatic') === 'optimized';
        $this->sqlLog = $options['sqllog'] ?? null;
    }

    public function s1Listing(Params $params): array
    {
        if ($this->optimized) {
            throw new ScenarioNotSupported('todo');
        }

        $de = IdBinding::value($params->languageId);
        $en = IdBinding::value($params->systemLanguageId);
        $select = $this->availableProducts($params)
            ->with('categoryTree', ['as' => 'ct', 'where' => ['categoryId' => IdBinding::value($params->categoryId)]])
            ->orderBy(new Fragment('COALESCE(t1.name, t2.name, t3.name, t4.name)'))
            ->orderBy('productNumber');

        $total = $select->count();
        $products = $select->limit($params->limit)->offset(($params->page - 1) * $params->limit)
            ->load('translations', ['method' => JoinableLoader::POSTLOAD, 'where' => ['languageId' => ['in' => [$de, $en]]]])
            ->load('manufacturer.translations', ['method' => JoinableLoader::POSTLOAD, 'where' => ['languageId' => ['in' => [$de, $en]]]])
            ->load('cover.media')
            ->load('parent.translations', ['method' => JoinableLoader::POSTLOAD, 'where' => ['languageId' => ['in' => [$de, $en]]]])
            ->load('parent.manufacturer.translations', ['method' => JoinableLoader::POSTLOAD, 'where' => ['languageId' => ['in' => [$de, $en]]]])
            ->load('parent.cover.media')
            ->fetchAll();

        $items = [];
        foreach ($products as $product) {
            $items[] = $this->listItem($product, $params, true);
        }

        return ['total' => $total, 'items' => $items];
    }

    public function s2Detail(Params $params): array
    {
        $in = ['method' => JoinableLoader::POSTLOAD, 'where' => ['languageId' => ['in' => $this->languages($params)]]];
        $product = $this->availableProducts($params)
            ->where('id', IdBinding::value($params->variantId))
            ->load('translations', $in)
            ->load('manufacturer.translations', $in)
            ->load('cover.media')
            ->load('prices')
            ->load('options.option.translations', $in)
            ->load('options.option.group.translations', $in)
            ->load('categories')
            ->load('parent.translations', $in)
            ->load('parent.manufacturer.translations', $in)
            ->load('parent.cover.media')
            ->load('parent.prices')
            ->load('parent.options.option.translations', $in)
            ->load('parent.options.option.group.translations', $in)
            ->load('parent.categories')
            ->fetchOne();
        if ($product === null) {
            throw new \RuntimeException('S2 variant not found or not visible: ' . $params->variantId);
        }

        $parent = $product->parent;
        $item = $this->listItem($product, $params, true);
        $manufacturer = $product->manufacturer ?? $parent?->manufacturer;
        // to-many inheritance: the rows of the variant if it has any, otherwise the rows of its parent
        $prices = $product->prices !== [] ? $product->prices : ($parent->prices ?? []);
        $options = $product->options !== [] ? $product->options : ($parent->options ?? []);
        $categories = $product->categories !== [] ? $product->categories : ($parent->categories ?? []);

        $priceRows = array_map(fn ($p): array => [
            'quantityStart' => $p->quantityStart,
            'quantityEnd' => $p->quantityEnd,
            'price' => $this->price($p->price, $params),
        ], $prices);
        usort($priceRows, static fn (array $a, array $b): int => $a['quantityStart'] <=> $b['quantityStart']);

        $optionList = array_map(fn ($o): array => [
            'group' => (string) $this->translatedName($o->option->group->translations, $params),
            'name' => (string) $this->translatedName($o->option->translations, $params),
        ], $options);
        usort($optionList, static fn (array $a, array $b): int => [$a['group'], $a['name']] <=> [$b['group'], $b['name']]);
        $categoryList = array_map(static fn ($c): string => $c->categoryId, $categories);
        sort($categoryList);

        return [
            'id' => $item['id'],
            'productNumber' => $item['productNumber'],
            'name' => $item['name'],
            'description' => $this->translated([$product, $parent], $params, 'description'),
            'manufacturerId' => $manufacturer?->id,
            'manufacturer' => $item['manufacturer'],
            'cover' => $item['cover'],
            'price' => $item['price'],
            'prices' => $priceRows,
            'options' => $optionList,
            'categories' => $categoryList,
        ];
    }

    public function s3Search(Params $params): array
    {
        $dialect = SqlDialectFactory::getDefault();
        $select = $this->availableProducts($params)
            ->where(new Fragment($dialect->caseInsensitive('COALESCE(t1.name, t2.name, t3.name, t4.name)') . ' LIKE ?', $dialect->caseInsensitiveValue('%' . $params->searchTerm . '%')))
            ->where(new Fragment('COALESCE(product.product_manufacturer_id, pp.product_manufacturer_id) IN (' . implode(',', array_fill(0, \count($params->manufacturerIds), '?')) . ')', ...IdBinding::values($params->manufacturerIds)));

        $total = $select->count();
        $price = 'CAST(' . $dialect->jsonExtractNumber('COALESCE(product.price, pp.price)', '$.c' . $params->currencyId . '.gross') . ' AS DECIMAL(20,4))';
        $stats = $select->buildQuery()->columns(new Fragment('MIN(' . $price . ') AS min_price'), new Fragment('MAX(' . $price . ') AS max_price'))->fetchAll();
        $terms = $select->buildQuery()->columns(
            new Fragment(IdBinding::text('COALESCE(product.product_manufacturer_id, pp.product_manufacturer_id)') . ' AS manufacturer_id'),
            new Fragment('COUNT(*) AS cnt')
        )->groupBy(new Fragment('COALESCE(product.product_manufacturer_id, pp.product_manufacturer_id)'))->fetchAll();

        $manufacturers = [];
        foreach ($terms as $term) {
            $manufacturers[IdBinding::toHex((string) $term['manufacturer_id'])] = (int) $term['cnt'];
        }
        ksort($manufacturers);

        $in = ['method' => JoinableLoader::POSTLOAD, 'where' => ['languageId' => ['in' => $this->languages($params)]]];
        $products = $select->orderBy(new Fragment('COALESCE(t1.name, t2.name, t3.name, t4.name)'))->orderBy('productNumber')->limit($params->limit)
            ->load('translations', $in)
            ->load('manufacturer.translations', $in)
            ->load('parent.translations', $in)
            ->load('parent.manufacturer.translations', $in)
            ->fetchAll();

        return [
            'total' => $total,
            'items' => array_map(fn (Product $p): array => $this->listItem($p, $params, false), $products),
            'aggregations' => [
                'minPrice' => ResultContract::price($stats[0]['min_price'] ?? null),
                'maxPrice' => ResultContract::price($stats[0]['max_price'] ?? null),
                'manufacturers' => $manufacturers,
            ],
        ];
    }

    public function s4Write(Params $params, WriteSet $set): void
    {
        $orm = $this->orm();
        $manager = new EntityManager($orm);
        $now = (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT);
        $products = [];
        foreach ($set->products as $row) {
            $product = new Product();
            $product->id = $row['id'];
            $product->versionId = $params->liveVersionId;
            $product->taxId = $params->taxId;
            $product->productNumber = $row['productNumber'];
            $product->stock = $row['stock'];
            $product->active = 1;
            $product->price = json_encode(['c' . $params->currencyId => ['currencyId' => $params->currencyId, 'gross' => $row['gross'], 'net' => $row['net'], 'linked' => true]], \JSON_THROW_ON_ERROR);
            $product->createdAt = $now;
            foreach ([[$params->systemLanguageId, $row['nameEn']], [$params->languageId, $row['nameDe']]] as [$language, $name]) {
                $translation = new ProductTranslation();
                $translation->languageId = $language;
                $translation->name = $name;
                $translation->createdAt = $now;
                $product->translations[] = $translation;
            }
            foreach ($row['categoryIds'] as $categoryId) {
                $link = new ProductCategory();
                $link->categoryId = $categoryId;
                $link->categoryVersionId = $params->liveVersionId;
                $product->categories[] = $link;
            }
            $visibility = new ProductVisibility();
            $visibility->id = md5('visibility' . $row['id']);
            $visibility->salesChannelId = $params->salesChannelId;
            $visibility->visibility = 30;
            $visibility->createdAt = $now;
            $product->visibilities[] = $visibility;

            $manager->persist($product);
            $products[$row['id']] = $product;
        }
        $manager->run();

        foreach ($set->products as $row) {
            $products[$row['id']]->stock = $row['newStock'];
            $products[$row['id']]->updatedAt = $now;
            $manager->persist($products[$row['id']]);
        }
        $manager->run();
    }

    public function s5CriteriaFromJson(Params $params): array
    {
        $json = $params->criteriaJson;
        $dialect = SqlDialectFactory::getDefault();
        $name = 'COALESCE(t1.name, t2.name, t3.name, t4.name)';
        // allow list: JSON field => SQL expression, anything else is rejected
        $fields = [
            'active' => 'COALESCE(product.active, pp.active)',
            'stock' => 'product.stock',
            'name' => $name,
            'productNumber' => 'product.product_number',
        ];

        $select = $this->availableProducts($params);
        foreach ($json['filter'] ?? [] as $filter) {
            $field = $fields[$filter['field'] ?? ''] ?? throw new \InvalidArgumentException('Field not allowed: ' . ($filter['field'] ?? ''));
            switch ($filter['type'] ?? '') {
                case 'equals':
                    $select->where(new Fragment($field . ' = ?', \is_bool($filter['value']) ? (int) $filter['value'] : $filter['value']));
                    break;
                case 'contains':
                    $select->where(new Fragment($dialect->caseInsensitive($field) . ' LIKE ?', $dialect->caseInsensitiveValue('%' . $filter['value'] . '%')));
                    break;
                case 'range':
                    foreach (['gte' => '>=', 'gt' => '>', 'lte' => '<=', 'lt' => '<'] as $key => $operator) {
                        if (isset($filter['parameters'][$key])) {
                            $select->where(new Fragment($field . ' ' . $operator . ' ?', (int) $filter['parameters'][$key]));
                        }
                    }
                    break;
                default:
                    throw new ScenarioNotSupported('Filter type not supported: ' . ($filter['type'] ?? ''));
            }
        }
        foreach ($json['sort'] ?? [] as $sort) {
            $field = $fields[$sort['field'] ?? ''] ?? throw new \InvalidArgumentException('Field not allowed: ' . ($sort['field'] ?? ''));
            $select->orderBy(new Fragment($field), strtoupper((string) ($sort['order'] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC');
        }

        $total = $select->count();
        $in = ['method' => JoinableLoader::POSTLOAD, 'where' => ['languageId' => ['in' => $this->languages($params)]]];
        $products = $select->limit((int) ($json['limit'] ?? 25))
            ->load('manufacturer.translations', $in)
            ->load('parent.manufacturer.translations', $in)
            ->fetchAll();

        $items = [];
        foreach ($products as $product) {
            $manufacturer = $product->manufacturer ?? $product->parent?->manufacturer;
            $items[] = [
                'id' => $product->id,
                'productNumber' => $product->productNumber,
                'manufacturer' => $manufacturer === null ? null : $this->translatedName($manufacturer->translations, $params),
            ];
        }

        return ['total' => $total, 'items' => $items];
    }

    /**
     * @return list<string>
     */
    private function languages(Params $params): array
    {
        return IdBinding::values($params->languageChain());
    }

    /**
     * A fresh unit of work per scenario call (the heap is the identity map), like one request.
     */
    private function orm(): ORM
    {
        $this->manager ??= CycleBootstrap::database($this->connection, $this->counter, $this->sqlLog);
        $this->orm ??= CycleBootstrap::orm($this->manager);

        return $this->orm->with(heap: new Heap());
    }

    /**
     * Live version, active (inherited) and visible in the sales channel, like `ProductAvailableFilter`; joins the translations of
     * the product and its parent in both languages for filter and sort.
     *
     * @return Select<Product>
     */
    private function availableProducts(Params $params): Select
    {
        $de = IdBinding::value($params->languageId);
        $en = IdBinding::value($params->systemLanguageId);
        $left = JoinableLoader::LEFT_JOIN;

        /** @var Select<Product> $select */
        $select = $this->orm()->getRepository(Product::class)->select();

        return $select
            ->with('parent', ['as' => 'pp', 'method' => $left])
            ->with('translations', ['as' => 't1', 'method' => $left, 'where' => ['languageId' => $de]])
            ->with('parent.translations', ['as' => 't2', 'method' => $left, 'where' => ['languageId' => $de]])
            ->with('translationsAlt', ['as' => 't3', 'method' => $left, 'where' => ['languageId' => $en]])
            ->with('parent.translationsAlt', ['as' => 't4', 'method' => $left, 'where' => ['languageId' => $en]])
            ->where('versionId', IdBinding::value($params->liveVersionId))
            ->where(new Fragment('COALESCE(product.active, pp.active) = 1'))
            ->where(new Fragment(
                'EXISTS (SELECT 1 FROM product_visibility v WHERE v.product_id = COALESCE(product.parent_id, product.id) AND v.product_version_id = product.version_id AND v.sales_channel_id = ? AND v.visibility >= 10)',
                IdBinding::value($params->salesChannelId)
            ));
    }

    /**
     * @return array<string, mixed>
     */
    private function listItem(Product $product, Params $params, bool $withCover): array
    {
        $parent = $product->parent;
        $manufacturer = $product->manufacturer ?? $parent?->manufacturer;
        $cover = $product->cover ?? $parent?->cover;
        $item = [
            'id' => $product->id,
            'productNumber' => $product->productNumber,
            'name' => $this->translated([$product, $parent], $params),
            'manufacturer' => $manufacturer === null ? null : $this->translatedName($manufacturer->translations, $params),
            'price' => $this->price($product->price ?? $parent?->price, $params),
        ];
        if ($withCover) {
            $item['cover'] = $cover?->media?->path;
        }

        return $item;
    }

    /**
     * Name of the first owner that has one in the requested language, then the same in the system language.
     *
     * @param array<Product|null> $owners
     */
    private function translated(array $owners, Params $params, string $field = 'name'): ?string
    {
        foreach ($params->languageChain() as $language) {
            foreach ($owners as $owner) {
                foreach ($owner?->translations ?? [] as $translation) {
                    if ($translation->languageId === $language && $translation->{$field} !== null) {
                        return $translation->{$field};
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param iterable<object{languageId: string, name: ?string}> $translations
     */
    private function translatedName(iterable $translations, Params $params): ?string
    {
        foreach ($params->languageChain() as $language) {
            foreach ($translations as $translation) {
                if ($translation->languageId === $language && $translation->name !== null) {
                    return $translation->name;
                }
            }
        }

        return null;
    }

    private function price(?string $json, Params $params): ?string
    {
        if ($json === null) {
            return null;
        }
        $gross = json_decode($json, true, 512, \JSON_THROW_ON_ERROR)['c' . $params->currencyId]['gross'] ?? null;

        return ResultContract::price($gross);
    }
}

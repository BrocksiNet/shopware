<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\Doctrine;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Shopware\Core\Framework\Adapter\Database\Dialect\IdBinding;
use Shopware\Core\Framework\Adapter\Database\Dialect\SqlDialect;
use Shopware\Core\Framework\Adapter\Database\Dialect\SqlDialectFactory;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Indexing\EntityIndexerRegistry;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\OrmSpike\Doctrine\Entity\Category;
use Shopware\Core\Framework\OrmSpike\Doctrine\Entity\Product;
use Shopware\Core\Framework\OrmSpike\Doctrine\Entity\ProductTranslation;
use Shopware\Core\Framework\OrmSpike\Doctrine\Entity\ProductVisibility;
use Shopware\Core\Framework\OrmSpike\Fair\WriteContract;
use Shopware\Core\Framework\OrmSpike\Fair\WrittenEvents;
use Shopware\Core\Framework\OrmSpike\Fair\WrittenProduct;
use Shopware\Core\Framework\OrmSpike\Harness\Implementation;
use Shopware\Core\Framework\OrmSpike\Harness\Params;
use Shopware\Core\Framework\OrmSpike\Harness\ResultContract;
use Shopware\Core\Framework\OrmSpike\Harness\WriteSet;

/**
 * Doctrine ORM 3.8 on the existing Shopware tables. Two modes (`--option mode=idiomatic|optimized`):
 *
 * - `idiomatic`: entities, DQL with fetch joins of the to-one associations, `Paginator`, lazy collections for translations, prices, options
 *   and categories (so the N+1 selects show up), entity writes through `persist` and `flush`.
 *
 * `--option full=1` adds what the DAL does before and after a write: Symfony validator constraints on the entities (before any `persist` reaches
 * the database), the write protection check, and an `EntityWrittenContainerEvent` for the create and for the stock update (docs/35).
 * The bare variants stay as they were. `optimized` sends the DQL `UPDATE` as before, so its update event is built by hand like the others.
 *
 * - `optimized`: DQL that selects scalars only (array hydration, no entities), joins as in the hand-written SQL, `COUNT(*) OVER()` through a
 *   custom DQL function, batched by-id reads for the to-many data, a DQL `UPDATE` for the stock.
 *
 * @internal
 */
#[Package('framework')]
final class DoctrineImplementation implements Implementation
{
    private const ALIASES = [
        't1' => ['p', 'de'], 't2' => ['pp', 'de'], 't3' => ['p', 'en'], 't4' => ['pp', 'en'],
    ];

    private bool $optimized = false;

    private bool $full = false;

    private ?SqlDialect $dialect = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WriteContract $contract,
        private readonly WrittenEvents $events,
    ) {
    }

    public static function key(): string
    {
        return 'doctrine';
    }

    public function configure(array $options): void
    {
        $this->optimized = ($options['mode'] ?? 'idiomatic') === 'optimized';
        $this->full = ($options['full'] ?? '0') === '1';
    }

    public function s1Listing(Params $params): array
    {
        $this->em->clear();
        $qb = $this->base($params, $this->optimized)
            ->andWhere('EXISTS (SELECT 1 FROM ' . Entity\ProductCategoryTree::class . ' c WHERE c.productId = p.id AND c.productVersionId = p.versionId AND c.categoryId = :cat)')
            ->setParameter('cat', $params->categoryId, 'shopware_id')
            ->orderBy('sortName')
            ->addOrderBy('p.productNumber')
            ->setFirstResult(($params->page - 1) * $params->limit)
            ->setMaxResults($params->limit);

        return $this->optimized ? $this->scalarListing($qb, $params, true) : $this->entityListing($qb, $params, true);
    }

    public function s2Detail(Params $params): array
    {
        $this->em->clear();
        $qb = $this->base($params, $this->optimized)->andWhere('p.id = :id')->setParameter('id', $params->variantId, 'shopware_id');

        return $this->optimized ? $this->scalarDetail($qb, $params) : $this->entityDetail($qb, $params);
    }

    public function s3Search(Params $params): array
    {
        $this->em->clear();
        $qb = $this->base($params, $this->optimized)
            ->andWhere('SW_CI(' . $this->name() . ') LIKE :term')
            ->andWhere('COALESCE(p.manufacturerId, pp.manufacturerId) IN (:mf)')
            ->setParameter('term', $this->dialect()->caseInsensitiveValue('%' . $params->searchTerm . '%'))
            ->setParameter('mf', IdBinding::values($params->manufacturerIds), IdBinding::listType())
            ->orderBy('sortName')
            ->addOrderBy('p.productNumber')
            ->setMaxResults($params->limit);

        $result = $this->optimized ? $this->scalarListing($qb, $params, false) : $this->entityListing($qb, $params, false);

        // aggregations: DQL aggregate queries over all matches, not only the page
        $agg = $this->base($params, true)->resetDQLPart('select')->select('MIN(SW_PRICE(p.price, pp.price, ' . $this->priceKey($params) . ')) AS lo, MAX(SW_PRICE(p.price, pp.price, ' . $this->priceKey($params) . ')) AS hi')
            ->resetDQLPart('orderBy')
            ->andWhere('SW_CI(' . $this->name() . ') LIKE :term')
            ->andWhere('COALESCE(p.manufacturerId, pp.manufacturerId) IN (:mf)')
            ->setParameter('term', $this->dialect()->caseInsensitiveValue('%' . $params->searchTerm . '%'))
            ->setParameter('mf', IdBinding::values($params->manufacturerIds), IdBinding::listType())
        ;
        $stats = $agg->getQuery()->getSingleResult();

        $terms = $this->base($params, true)->resetDQLPart('select')->select('COALESCE(p.manufacturerId, pp.manufacturerId) AS mid, COUNT(p.id) AS cnt')
            ->andWhere('SW_CI(' . $this->name() . ') LIKE :term')
            ->andWhere('COALESCE(p.manufacturerId, pp.manufacturerId) IN (:mf)')
            ->setParameter('term', $this->dialect()->caseInsensitiveValue('%' . $params->searchTerm . '%'))
            ->setParameter('mf', IdBinding::values($params->manufacturerIds), IdBinding::listType())
            ->groupBy('mid')
            ->getQuery()->getArrayResult();

        $manufacturers = [];
        foreach ($terms as $term) {
            $manufacturers[IdBinding::toHex((string) $term['mid'])] = (int) $term['cnt'];
        }
        ksort($manufacturers);

        return $result + ['aggregations' => [
            'minPrice' => ResultContract::price($stats['lo'] === null ? null : (float) $stats['lo']),
            'maxPrice' => ResultContract::price($stats['hi'] === null ? null : (float) $stats['hi']),
            'manufacturers' => $manufacturers,
        ]];
    }

    public function s4Write(Params $params, WriteSet $set): void
    {
        $now = new \DateTimeImmutable();
        $live = $params->liveVersionId;
        $products = [];

        foreach ($set->products as $row) {
            $product = new Product();
            $product->id = $row['id'];
            $product->versionId = $live;
            $product->productNumber = $row['productNumber'];
            $product->stock = $row['stock'];
            $product->active = 1;
            $product->taxId = $params->taxId;
            $product->createdAt = $now;
            $product->price = ['c' . $params->currencyId => ['currencyId' => $params->currencyId, 'gross' => $row['gross'], 'net' => $row['net'], 'linked' => true]];

            foreach ([[$params->systemLanguageId, $row['nameEn']], [$params->languageId, $row['nameDe']]] as [$language, $name]) {
                $translation = new ProductTranslation();
                $translation->product = $product;
                $translation->productId = $row['id'];
                $translation->productVersionId = $live;
                $translation->languageId = $language;
                $translation->name = $name;
                $translation->createdAt = $now;
                $product->translations->add($translation);
            }
            foreach ($row['categoryIds'] as $categoryId) {
                $product->categories->add($this->em->getReference(Category::class, ['id' => $categoryId, 'versionId' => $live]));
            }
            $visibility = new ProductVisibility();
            $visibility->id = md5('visibility' . $row['id']);
            $visibility->product = $product;
            $visibility->salesChannelId = $params->salesChannelId;
            $visibility->visibility = 30;
            $visibility->createdAt = $now;
            $product->visibilities->add($visibility);

            $this->em->persist($product);
            $products[$row['id']] = $product;
        }

        if ($this->full) {
            $context = Context::createDefaultContext();
            $context->addState(EntityIndexerRegistry::DISABLE_INDEXING);
            $this->guard(fn () => $this->checkCreate($products, $context));
        }
        $this->em->flush();
        if ($this->full) {
            $this->events->created($this->written($products), $params, $context);
        }

        if ($this->full) {
            foreach ($set->products as $row) {
                $products[$row['id']]->stock = $row['newStock'];
                $products[$row['id']]->updatedAt = $now;
            }
            $this->guard(fn () => $this->checkUpdate($products, $context));
        }

        if ($this->optimized) {
            // one DQL UPDATE statement, bypasses the unit of work (no lifecycle events, managed entities are stale)
            $cases = '';
            $binds = [];
            foreach ($set->products as $i => $row) {
                $cases .= ' WHEN p.id = :i' . $i . ' THEN ' . (int) $row['newStock'];
                $binds['i' . $i] = $row['id'];
            }
            $query = $this->em->createQuery(\sprintf('UPDATE %s p SET p.stock = CASE%s ELSE p.stock END, p.updatedAt = :now WHERE p.versionId = :v AND p.id IN (:ids)', Product::class, $cases));
            foreach ($binds as $name => $value) {
                $query->setParameter($name, $value, 'shopware_id');
            }
            $query->setParameter('now', $now, 'datetime_immutable')
                ->setParameter('v', $live, 'shopware_id')
                ->setParameter('ids', IdBinding::values($set->ids()), IdBinding::listType())
                ->execute();
        } else {
            foreach ($set->products as $row) {
                $products[$row['id']]->stock = $row['newStock'];
                $products[$row['id']]->updatedAt = $now;
            }
            $this->em->flush();
        }
        if ($this->full) {
            $this->events->stockUpdated($this->written($products), $params, $context);
        }
        $this->em->clear();
    }

    public function s5CriteriaFromJson(Params $params): array
    {
        $this->em->clear();
        $qb = $this->base($params, $this->optimized);
        (new Query\CriteriaTranslator($this->name()))->apply($qb, $params->criteriaJson, $this->dialect());

        $criteria = $params->criteriaJson;
        $qb->setMaxResults((int) ($criteria['limit'] ?? 25));

        $result = $this->optimized ? $this->scalarListing($qb, $params, false) : $this->entityListing($qb, $params, false);

        return [
            'total' => $result['total'],
            'items' => array_map(static fn (array $i): array => ['id' => $i['id'], 'productNumber' => $i['productNumber'], 'manufacturer' => $i['manufacturer']], $result['items']),
        ];
    }

    /**
     * Runs a check before the write, a rejection drops everything the unit of work has scheduled.
     */
    private function guard(\Closure $check): void
    {
        try {
            $check();
        } catch (\Throwable $e) {
            $this->em->clear();

            throw $e;
        }
    }

    /**
     * @param array<string, Product> $products
     */
    private function checkCreate(array $products, Context $context): void
    {
        $this->contract->validate($products);
        foreach ($products as $product) {
            $this->contract->assertWritable('product', WriteContract::properties($product), $context);
            foreach ($product->translations as $translation) {
                $this->contract->assertWritable('product_translation', WriteContract::properties($translation), $context);
            }
            foreach ($product->visibilities as $visibility) {
                $this->contract->assertWritable('product_visibility', WriteContract::properties($visibility), $context);
            }
        }
    }

    /**
     * @param array<string, Product> $products
     */
    private function checkUpdate(array $products, Context $context): void
    {
        $this->contract->validate($products, 'stock');
        foreach ($products as $product) {
            $this->contract->assertWritable('product', ['id' => $product->id, 'stock' => $product->stock, 'updatedAt' => $product->updatedAt], $context);
        }
    }

    /**
     * What was written, read back from the entities.
     *
     * @param array<string, Product> $products
     *
     * @return list<WrittenProduct>
     */
    private function written(array $products): array
    {
        $written = [];
        foreach ($products as $product) {
            $price = reset($product->price);
            $translations = [];
            foreach ($product->translations as $translation) {
                $translations[] = ['languageId' => $translation->languageId, 'name' => (string) $translation->name];
            }
            $visibilities = [];
            foreach ($product->visibilities as $visibility) {
                $visibilities[] = ['id' => $visibility->id, 'salesChannelId' => $visibility->salesChannelId, 'visibility' => $visibility->visibility];
            }
            $written[] = new WrittenProduct(
                $product->id,
                $product->productNumber,
                $product->stock,
                $product->stock,
                (string) $product->taxId,
                $product->active === 1,
                (string) $price['currencyId'],
                (float) $price['gross'],
                (float) $price['net'],
                $translations,
                $product->getCategoryIds(),
                $visibilities
            );
        }

        return $written;
    }

    private function dialect(): SqlDialect
    {
        return $this->dialect ??= SqlDialectFactory::getDefault();
    }

    /**
     * The price key (`c<currencyId>`) is written into the DQL as a literal, the DQL function needs it at translation time, not as a bound value.
     */
    private function priceKey(Params $params): string
    {
        if (!ctype_xdigit($params->currencyId)) {
            throw new \InvalidArgumentException('Invalid currency id');
        }

        return '\'c' . $params->currencyId . '\'';
    }

    private function name(): string
    {
        return 'COALESCE(t1.name, t2.name, t3.name, t4.name)';
    }

    /**
     * The shared query: live version, active (inherited), visible in the sales channel, translation joins for sorting and filtering.
     * The scalar mode joins manufacturer and cover through the inherited id, the entity mode through the associations (fetch joins).
     */
    private function base(Params $params, bool $scalar): QueryBuilder
    {
        $qb = $this->em->createQueryBuilder()->from(Product::class, 'p')->leftJoin('p.parent', 'pp');

        foreach (self::ALIASES as $alias => [$owner, $lang]) {
            $qb->leftJoin(ProductTranslation::class, $alias, Join::WITH, \sprintf('%1$s.productId = %2$s.id AND %1$s.productVersionId = %2$s.versionId AND %1$s.languageId = :%3$s', $alias, $owner, $lang));
        }

        if ($scalar) {
            $qb->leftJoin(Entity\Manufacturer::class, 'm', Join::WITH, 'm.id = COALESCE(p.manufacturerId, pp.manufacturerId)')
                ->leftJoin(Entity\ManufacturerTranslation::class, 'mt1', Join::WITH, 'mt1.manufacturerId = m.id AND mt1.languageId = :de')
                ->leftJoin(Entity\ManufacturerTranslation::class, 'mt2', Join::WITH, 'mt2.manufacturerId = m.id AND mt2.languageId = :en')
                ->leftJoin(Entity\ProductMedia::class, 'pm', Join::WITH, 'pm.id = COALESCE(p.coverId, pp.coverId) AND pm.versionId = p.versionId')
                ->leftJoin('pm.media', 'md')
                ->select('p.id AS id, p.productNumber AS productNumber, ' . $this->name() . ' AS name, COALESCE(mt1.name, mt2.name) AS manufacturer, md.path AS cover, SW_PRICE(p.price, pp.price, ' . $this->priceKey($params) . ') AS price')
                ->addSelect($this->name() . ' AS HIDDEN sortName');
        } else {
            $qb->leftJoin('p.manufacturer', 'm1')->leftJoin('pp.manufacturer', 'm2')
                ->leftJoin('p.cover', 'pm1')->leftJoin('pm1.media', 'md1')
                ->leftJoin('pp.cover', 'pm2')->leftJoin('pm2.media', 'md2')
                ->select('p', 'pp', 'm1', 'm2', 'pm1', 'md1', 'pm2', 'md2')
                ->addSelect($this->name() . ' AS HIDDEN sortName');
        }

        return $qb
            ->where('p.versionId = :v AND COALESCE(p.active, pp.active) = 1')
            ->andWhere('EXISTS (SELECT 1 FROM ' . ProductVisibility::class . ' vis WHERE vis.productId = COALESCE(p.parentId, p.id) AND vis.productVersionId = p.versionId AND vis.salesChannelId = :sc AND vis.visibility >= 10)')
            ->setParameter('de', $params->languageId, 'shopware_id')
            ->setParameter('en', $params->systemLanguageId, 'shopware_id')
            ->setParameter('v', $params->liveVersionId, 'shopware_id')
            ->setParameter('sc', $params->salesChannelId, 'shopware_id');
    }

    /**
     * @return array{total: int, items: list<array<string, mixed>>}
     */
    private function scalarListing(QueryBuilder $qb, Params $params, bool $withCover): array
    {
        $qb->addSelect('SW_TOTAL() AS total');
        $rows = $qb->getQuery()->getArrayResult();

        $items = [];
        foreach ($rows as $row) {
            $item = [
                'id' => $row['id'],
                'productNumber' => $row['productNumber'],
                'name' => $row['name'],
                'manufacturer' => $row['manufacturer'],
                'price' => ResultContract::price($row['price'] === null ? null : (float) $row['price']),
            ];
            if ($withCover) {
                $item['cover'] = $row['cover'];
            }
            $items[] = $item;
        }

        return ['total' => (int) ($rows[0]['total'] ?? 0), 'items' => $items];
    }

    /**
     * @return array{total: int, items: list<array<string, mixed>>}
     */
    private function entityListing(QueryBuilder $qb, Params $params, bool $withCover): array
    {
        $paginator = new Paginator($qb->getQuery(), false);
        $paginator->setUseOutputWalkers(true);

        $items = [];
        foreach ($paginator as $product) {
            \assert($product instanceof Product);
            $item = [
                'id' => $product->id,
                'productNumber' => $product->productNumber,
                'name' => $this->translated($product, $params, static fn (ProductTranslation $t): ?string => $t->name),
                'manufacturer' => $this->manufacturerName($product, $params),
                'price' => ResultContract::price($this->grossOf($product->price ?? $product->parent?->price, $params)),
            ];
            if ($withCover) {
                $item['cover'] = ($product->cover ?? $product->parent?->cover)?->media->path;
            }
            $items[] = $item;
        }

        return ['total' => \count($paginator), 'items' => $items];
    }

    /**
     * @param callable(ProductTranslation): ?string $field
     */
    private function translated(Product $product, Params $params, callable $field): ?string
    {
        // own de, parent de, own en, parent en
        foreach ([[$product, $params->languageId], [$product->parent, $params->languageId], [$product, $params->systemLanguageId], [$product->parent, $params->systemLanguageId]] as [$owner, $language]) {
            $translation = $this->in($owner?->translations, $language);
            if ($translation !== null && $field($translation) !== null) {
                return $field($translation);
            }
        }

        return null;
    }

    /**
     * `indexBy` returns the raw database value as key (binary or dashed uuid), not the converted hex id, so the collection is searched.
     *
     * @template T of object
     *
     * @param Collection<int, T>|null $translations
     *
     * @return T|null
     */
    private function in(?Collection $translations, string $languageId): ?object
    {
        foreach ($translations ?? [] as $translation) {
            if ($translation->languageId === $languageId) {
                return $translation;
            }
        }

        return null;
    }

    private function manufacturerName(Product $product, Params $params): ?string
    {
        $manufacturer = $product->manufacturer ?? $product->parent?->manufacturer;

        return $this->in($manufacturer?->translations, $params->languageId)?->name ?? $this->in($manufacturer?->translations, $params->systemLanguageId)?->name;
    }

    /**
     * @param array<string, array<string, mixed>>|null $price
     */
    private function grossOf(?array $price, Params $params): ?float
    {
        $gross = $price['c' . $params->currencyId]['gross'] ?? null;

        return $gross === null ? null : (float) $gross;
    }

    /**
     * @return array<string, mixed>
     */
    private function entityDetail(QueryBuilder $qb, Params $params): array
    {
        $product = $qb->getQuery()->getOneOrNullResult();
        if (!$product instanceof Product) {
            throw new \RuntimeException('S2 variant not found or not visible: ' . $params->variantId);
        }
        $parent = $product->parent;
        $manufacturer = $product->manufacturer ?? $parent?->manufacturer;

        // to-many inheritance: own rows if the variant has any, else the parent's (each access is a lazy load)
        $prices = $product->prices->isEmpty() && $parent !== null ? $parent->prices : $product->prices;
        $options = $product->options->isEmpty() && $parent !== null ? $parent->options : $product->options;
        $categories = $product->categories->isEmpty() && $parent !== null ? $parent->categories : $product->categories;

        $priceRows = $prices->toArray();
        usort($priceRows, static fn (Entity\ProductPrice $a, Entity\ProductPrice $b): int => $a->quantityStart <=> $b->quantityStart);

        $optionList = [];
        foreach ($options as $option) {
            $optionList[] = [
                'group' => (string) ($this->in($option->group->translations, $params->languageId)?->name ?? $this->in($option->group->translations, $params->systemLanguageId)?->name),
                'name' => (string) ($this->in($option->translations, $params->languageId)?->name ?? $this->in($option->translations, $params->systemLanguageId)?->name),
            ];
        }
        usort($optionList, static fn (array $a, array $b): int => [$a['group'], $a['name']] <=> [$b['group'], $b['name']]);

        $categoryList = array_map(static fn (Category $c): string => $c->id, $categories->toArray());
        sort($categoryList);

        return [
            'id' => $product->id,
            'productNumber' => $product->productNumber,
            'name' => $this->translated($product, $params, static fn (ProductTranslation $t): ?string => $t->name),
            'description' => $this->translated($product, $params, static fn (ProductTranslation $t): ?string => $t->description),
            'manufacturerId' => $manufacturer?->id,
            'manufacturer' => $this->manufacturerName($product, $params),
            'cover' => ($product->cover ?? $parent?->cover)?->media->path,
            'price' => ResultContract::price($this->grossOf($product->price ?? $parent?->price, $params)),
            'prices' => array_map(fn (Entity\ProductPrice $p): array => [
                'quantityStart' => $p->quantityStart,
                'quantityEnd' => $p->quantityEnd,
                'price' => ResultContract::price($this->grossOf($p->price, $params)),
            ], $priceRows),
            'options' => $optionList,
            'categories' => $categoryList,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function scalarDetail(QueryBuilder $qb, Params $params): array
    {
        $qb->addSelect('COALESCE(t1.description, t2.description, t3.description, t4.description) AS description, COALESCE(p.manufacturerId, pp.manufacturerId) AS manufacturerId, p.parentId AS parentId');
        $row = $qb->getQuery()->getArrayResult()[0] ?? throw new \RuntimeException('S2 variant not found or not visible: ' . $params->variantId);

        $ids = [IdBinding::value($params->variantId)];
        if ($row['parentId'] !== null) {
            $ids[] = IdBinding::value((string) $row['parentId']);
        }
        $own = $params->variantId;
        $binds = static fn (QueryBuilder $q): QueryBuilder => $q
            ->setParameter('ids', $ids, IdBinding::listType())
            ->setParameter('v', $params->liveVersionId, 'shopware_id');

        $prices = $binds($this->em->createQueryBuilder()->select('pr.productId AS owner, pr.quantityStart AS qs, pr.quantityEnd AS qe, pr.price AS price')
            ->from(Entity\ProductPrice::class, 'pr')->where('pr.productId IN (:ids) AND pr.productVersionId = :v')->orderBy('pr.quantityStart'))
            ->getQuery()->getArrayResult();
        $prices = $this->preferOwn($prices, $own);

        $options = $binds($this->em->createQueryBuilder()
            ->select('p.id AS owner, COALESCE(gt1.name, gt2.name) AS groupName, COALESCE(ot1.name, ot2.name) AS optionName')
            ->from(Product::class, 'p')->join('p.options', 'o')->join('o.group', 'g')
            ->leftJoin('o.translations', 'ot1', Join::WITH, 'ot1.languageId = :de')->leftJoin('o.translations', 'ot2', Join::WITH, 'ot2.languageId = :en')
            ->leftJoin('g.translations', 'gt1', Join::WITH, 'gt1.languageId = :de')->leftJoin('g.translations', 'gt2', Join::WITH, 'gt2.languageId = :en')
            ->where('p.id IN (:ids) AND p.versionId = :v'))
            ->setParameter('de', $params->languageId, 'shopware_id')->setParameter('en', $params->systemLanguageId, 'shopware_id')
            ->getQuery()->getArrayResult();
        $options = $this->preferOwn($options, $own);

        $categories = $binds($this->em->createQueryBuilder()->select('p.id AS owner, c.id AS categoryId')
            ->from(Product::class, 'p')->join('p.categories', 'c')->where('p.id IN (:ids) AND p.versionId = :v'))
            ->getQuery()->getArrayResult();
        $categories = $this->preferOwn($categories, $own);

        $optionList = array_map(static fn (array $o): array => ['group' => (string) $o['groupName'], 'name' => (string) $o['optionName']], $options);
        usort($optionList, static fn (array $a, array $b): int => [$a['group'], $a['name']] <=> [$b['group'], $b['name']]);
        $categoryList = array_map(static fn (array $c): string => (string) $c['categoryId'], $categories);
        sort($categoryList);

        return [
            'id' => $row['id'],
            'productNumber' => $row['productNumber'],
            'name' => $row['name'],
            'description' => $row['description'],
            'manufacturerId' => $row['manufacturerId'] === null ? null : IdBinding::toHex((string) $row['manufacturerId']),
            'manufacturer' => $row['manufacturer'],
            'cover' => $row['cover'],
            'price' => ResultContract::price($row['price'] === null ? null : (float) $row['price']),
            'prices' => array_map(fn (array $p): array => [
                'quantityStart' => (int) $p['qs'],
                'quantityEnd' => $p['qe'] === null ? null : (int) $p['qe'],
                'price' => ResultContract::price($this->grossOf($p['price'], $params)),
            ], $prices),
            'options' => $optionList,
            'categories' => $categoryList,
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function preferOwn(array $rows, string $ownId): array
    {
        $own = array_values(array_filter($rows, static fn (array $r): bool => $r['owner'] === $ownId));

        return $own !== [] ? $own : $rows;
    }
}

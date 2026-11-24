<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Builder;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Adapter\Database\Dialect\IdBinding;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\ReadStore\Document\ProductDocument;

/**
 * Builds the documents with hand-written SQL (one statement per table and batch), the way `ElasticsearchProductDefinition::fetch()` does.
 *
 * Inheritance follows the DAL with `considerInheritance`:
 * - inherited columns: own value, the parent's when the own is `NULL`;
 * - translated fields: first non-`NULL` of `[own L, parent L, own L2, parent L2, ...]` over the language chain, custom fields are merged in that
 *   priority like `EntityHydrator::customFields()`;
 * - one-to-many associations (`media`, `prices`, `visibilities`): the own rows, the parent's when there are none (`EntityReader`);
 * - many-to-many associations (`properties`): the rows of the product the inheritance column (`product.properties`) points to;
 * - not inherited: `options`, `searchKeywords`, `stock`, `availableStock`, `available`, `sales`, `displayGroup`, `childCount`, `states`.
 *
 * @internal
 */
#[Package('framework')]
final class SqlDocumentBuilder implements DocumentBuilder
{
    /**
     * Inherited scalar columns: column => [document field, type].
     */
    private const INHERITED = [
        'active' => ['active', 'bool'],
        'is_closeout' => ['isCloseout', 'bool'],
        'min_purchase' => ['minPurchase', 'int'],
        'max_purchase' => ['maxPurchase', 'int'],
        'purchase_steps' => ['purchaseSteps', 'int'],
        'purchase_unit' => ['purchaseUnit', 'float'],
        'reference_unit' => ['referenceUnit', 'float'],
        'restock_time' => ['restockTime', 'int'],
        'ean' => ['ean', 'string'],
        'manufacturer_number' => ['manufacturerNumber', 'string'],
        'weight' => ['weight', 'float'],
        'rating_average' => ['ratingAverage', 'float'],
        'mark_as_topseller' => ['markAsTopseller', 'bool'],
        'shipping_free' => ['shippingFree', 'bool'],
        'release_date' => ['releaseDate', 'date'],
        'variant_listing_config' => ['variantListingConfig', 'json'],
        'category_ids' => ['categoryIds', 'list'],
        'category_tree' => ['categoryTree', 'list'],
        'stream_ids' => ['streamIds', 'list'],
        'tag_ids' => ['tagIds', 'list'],
        'property_ids' => ['propertyIds', 'list'],
        'option_ids' => ['optionIds', 'list'],
        'product_manufacturer_id' => ['manufacturerId', 'string'],
        'tax_id' => ['taxId', 'string'],
        'unit_id' => ['unitId', 'string'],
        'delivery_time_id' => ['deliveryTimeId', 'string'],
        'product_media_id' => ['coverId', 'string'],
    ];

    private const ID_COLUMNS = ['id', 'parent_id', 'product_manufacturer_id', 'tax_id', 'unit_id', 'delivery_time_id', 'product_media_id', 'properties'];

    private const OTHER_COLUMNS = [
        'product_number', 'display_group', 'child_count', 'available', 'stock', 'available_stock', 'sales', 'created_at', 'updated_at', 'states',
        'price', 'cheapest_price_accessor',
    ];

    private const TRANSLATED = [
        'name' => 'name',
        'description' => 'description',
        'meta_title' => 'metaTitle',
        'meta_description' => 'metaDescription',
        'keywords' => 'keywords',
        'pack_unit' => 'packUnit',
        'pack_unit_plural' => 'packUnitPlural',
    ];

    private ?BuildScope $scope = null;

    private ?PriceCalculator $calculator = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly DocumentExtenders $extenders,
    ) {
    }

    public static function key(): string
    {
        return 'sql';
    }

    /**
     * Reloads languages and currencies on the next build (call it before a rebuild).
     */
    public function reset(): void
    {
        $this->scope = null;
        $this->calculator = null;
    }

    public function build(array $ids): array
    {
        $ids = array_values(array_unique(array_map(strtolower(...), $ids)));
        if ($ids === []) {
            return [];
        }

        $scope = $this->scope ??= BuildScope::load($this->connection);
        $this->calculator ??= new PriceCalculator($scope);

        $rows = $this->products($ids);
        $parentIds = array_values(array_diff(array_unique(array_filter(array_column($rows, 'parent_id'))), array_keys($rows)));
        $parents = $this->products($parentIds);
        $all = $rows + $parents;
        $allIds = array_keys($all);

        $translations = $this->translations($allIds);
        $media = $this->grouped(
            \sprintf(
                'SELECT %s AS owner, %s AS id, %s AS media_id, pm.position, m.path
                 FROM product_media pm LEFT JOIN media m ON m.id = pm.media_id
                 WHERE pm.product_id IN (:ids) AND pm.product_version_id = :version ORDER BY pm.position, pm.id',
                IdBinding::text('pm.product_id'),
                IdBinding::text('pm.id'),
                IdBinding::text('pm.media_id')
            ),
            $allIds
        );
        $prices = $this->grouped(
            \sprintf(
                'SELECT %s AS owner, %s AS id, %s AS rule_id, quantity_start, quantity_end, price
                 FROM product_price WHERE product_id IN (:ids) AND product_version_id = :version ORDER BY rule_id, quantity_start, id',
                IdBinding::text('product_id'),
                IdBinding::text('id'),
                IdBinding::text('rule_id')
            ),
            $allIds
        );
        $visibilities = $this->grouped(
            \sprintf(
                'SELECT %s AS owner, %s AS sales_channel_id, visibility FROM product_visibility WHERE product_id IN (:ids) AND product_version_id = :version',
                IdBinding::text('product_id'),
                IdBinding::text('sales_channel_id')
            ),
            $allIds
        );
        $propertySources = array_values(array_unique(array_filter(array_column($rows, 'properties'))));
        $propertyLinks = $this->grouped(
            \sprintf(
                'SELECT %s AS owner, %s AS option_id FROM product_property WHERE product_id IN (:ids) AND product_version_id = :version',
                IdBinding::text('product_id'),
                IdBinding::text('property_group_option_id')
            ),
            $propertySources
        );
        $optionLinks = $this->grouped(
            \sprintf(
                'SELECT %s AS owner, %s AS option_id FROM product_option WHERE product_id IN (:ids) AND product_version_id = :version',
                IdBinding::text('product_id'),
                IdBinding::text('property_group_option_id')
            ),
            $ids
        );
        $keywords = $this->grouped(
            \sprintf(
                'SELECT %s AS owner, %s AS language_id, keyword, ranking FROM product_search_keyword
                 WHERE product_id IN (:ids) AND product_version_id = :version ORDER BY keyword',
                IdBinding::text('product_id'),
                IdBinding::text('language_id')
            ),
            $ids
        );

        // the effective (inherited) values decide which referenced rows are needed
        $effective = [];
        foreach ($rows as $id => $row) {
            $parent = $row['parent_id'] !== null ? ($all[$row['parent_id']] ?? null) : null;
            foreach (self::INHERITED as $column => [$field, $type]) {
                $effective[$id][$field] = self::value($row[$column] ?? $parent[$column] ?? null, $type);
            }
        }

        $optionIds = [];
        foreach ([$propertyLinks, $optionLinks] as $links) {
            foreach ($links as $list) {
                array_push($optionIds, ...array_column($list, 'option_id'));
            }
        }

        $manufacturers = $this->manufacturers(self::column($effective, 'manufacturerId'));
        $taxes = $this->byId(
            \sprintf('SELECT %s AS id, tax_rate, name FROM tax WHERE id IN (:ids)', IdBinding::text('id')),
            self::column($effective, 'taxId'),
            false
        );
        $units = $this->translatedEntities('unit', 'unit_translation', 'unit_id', ['short_code' => 'shortCode', 'name' => 'name'], [], self::column($effective, 'unitId'), false);
        $deliveryTimes = $this->translatedEntities('delivery_time', 'delivery_time_translation', 'delivery_time_id', ['name' => 'name'], ['min', 'max', 'unit'], self::column($effective, 'deliveryTimeId'), false);
        $options = $this->options(array_values(array_unique($optionIds)));
        $covers = $this->byId(
            \sprintf(
                'SELECT %s AS id, %s AS media_id, pm.position, m.path FROM product_media pm LEFT JOIN media m ON m.id = pm.media_id
                 WHERE pm.id IN (:ids) AND pm.version_id = :version',
                IdBinding::text('pm.id'),
                IdBinding::text('pm.media_id')
            ),
            self::column($effective, 'coverId'),
            true
        );

        $documents = [];
        foreach ($ids as $id) {
            $row = $rows[$id] ?? null;
            if ($row === null) {
                continue;
            }
            $parentId = $row['parent_id'];
            $parent = $parentId !== null ? ($all[$parentId] ?? null) : null;
            $values = $effective[$id];

            $document = new ProductDocument();
            $document->id = $id;
            $document->parentId = $parentId;
            $document->productNumber = (string) $row['product_number'];
            $document->displayGroup = $row['display_group'];
            $document->childCount = (int) $row['child_count'];
            $document->available = (bool) $row['available'];
            $document->stock = (int) $row['stock'];
            $document->availableStock = $row['available_stock'] === null ? null : (int) $row['available_stock'];
            $document->sales = (int) $row['sales'];
            $document->createdAt = Normalizer::date($row['created_at']);
            $document->updatedAt = Normalizer::date($row['updated_at']);
            $document->states = self::value($row['states'], 'list') ?? [];
            foreach (self::INHERITED as [$field]) {
                $document->{$field} = $values[$field] ?? (\in_array($field, ['categoryIds', 'categoryTree', 'streamIds', 'tagIds', 'propertyIds', 'optionIds'], true) ? [] : null);
            }

            $own = $translations[$id] ?? [];
            $inherited = $parentId !== null ? ($translations[$parentId] ?? []) : [];
            foreach ($scope->languageChains as $languageId => $chain) {
                foreach (self::TRANSLATED as $column => $field) {
                    $document->{$field}[$languageId] = self::translated($chain, $column, $own, $inherited);
                }
                $document->customFields[$languageId] = self::customFields($chain, $own, $inherited);
            }

            $document->manufacturer = $document->manufacturerId !== null ? ($manufacturers[$document->manufacturerId] ?? null) : null;
            $tax = $document->taxId !== null ? ($taxes[$document->taxId] ?? null) : null;
            $document->tax = $tax === null ? null : ['id' => $tax['id'], 'taxRate' => (float) $tax['tax_rate'], 'name' => $tax['name']];
            $document->unit = $document->unitId !== null ? ($units[$document->unitId] ?? null) : null;
            $deliveryTime = $document->deliveryTimeId !== null ? ($deliveryTimes[$document->deliveryTimeId] ?? null) : null;
            // plain columns come back as the driver returns them (strings on MySQL with emulated prepares)
            $document->deliveryTime = $deliveryTime === null ? null : array_replace($deliveryTime, ['min' => (int) $deliveryTime['min'], 'max' => (int) $deliveryTime['max']]);
            $cover = $document->coverId !== null ? ($covers[$document->coverId] ?? null) : null;
            $document->cover = $cover === null ? null : self::media($cover);

            $document->media = array_map(self::media(...), self::ownOrParent($media, $id, $parentId));
            $document->prices = array_map(static fn (array $price): array => [
                'id' => $price['id'],
                'ruleId' => $price['rule_id'],
                'quantityStart' => (int) $price['quantity_start'],
                'quantityEnd' => $price['quantity_end'] === null ? null : (int) $price['quantity_end'],
                'price' => self::priceList($price['price']),
            ], self::ownOrParent($prices, $id, $parentId));
            foreach (self::ownOrParent($visibilities, $id, $parentId) as $visibility) {
                $document->visibilities[(string) $visibility['sales_channel_id']] = (int) $visibility['visibility'];
            }

            $ownPrice = self::json($row['price']);
            $document->price = self::priceList($row['price'] ?? $parent['price'] ?? null);
            $currencyPrices = $this->calculator->currencyPrices($ownPrice, $parent === null ? null : self::json($parent['price']));
            $document->priceGross = $currencyPrices['gross'];
            $document->priceNet = $currencyPrices['net'];
            $document->cheapestPrice = $this->calculator->cheapestPrice(self::json($row['cheapest_price_accessor'] ?? $parent['cheapest_price_accessor'] ?? null));

            $document->properties = self::optionList($row['properties'] !== null ? ($propertyLinks[$row['properties']] ?? []) : [], $options);
            $document->options = self::optionList($optionLinks[$id] ?? [], $options);

            foreach ($scope->languageIds() as $languageId) {
                $document->searchKeywords[$languageId] = [];
            }
            foreach ($keywords[$id] ?? [] as $keyword) {
                $document->searchKeywords[(string) $keyword['language_id']][(string) $keyword['keyword']] = (float) $keyword['ranking'];
            }
            foreach ($document->searchKeywords as &$languageKeywords) {
                ksort($languageKeywords, \SORT_STRING);
            }
            unset($languageKeywords);

            $documents[$id] = $document->toArray();
        }

        return $this->extenders->apply($documents);
    }

    /**
     * @param list<string> $ids
     *
     * @return array<string, array<string, mixed>>
     */
    private function products(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $columns = array_map(static fn (string $column): string => IdBinding::text($column) . ' AS ' . $column, self::ID_COLUMNS);
        foreach (array_merge(array_keys(self::INHERITED), self::OTHER_COLUMNS) as $column) {
            if (!\in_array($column, self::ID_COLUMNS, true)) {
                $columns[] = $column;
            }
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT ' . implode(', ', array_unique($columns)) . ' FROM product WHERE id IN (:ids) AND version_id = :version',
            ['ids' => IdBinding::values($ids), 'version' => IdBinding::value(Defaults::LIVE_VERSION)],
            ['ids' => IdBinding::listType(), 'version' => IdBinding::type()]
        );

        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['id']] = $row;
        }

        return $result;
    }

    /**
     * @param list<string> $ids
     *
     * @return array<string, array<string, array<string, mixed>>> product id => language id => row
     */
    private function translations(array $ids): array
    {
        $rows = $this->connection->fetchAllAssociative(
            \sprintf(
                'SELECT %s AS product_id, %s AS language_id, %s, custom_fields FROM product_translation
                 WHERE product_id IN (:ids) AND product_version_id = :version',
                IdBinding::text('product_id'),
                IdBinding::text('language_id'),
                implode(', ', array_keys(self::TRANSLATED))
            ),
            ['ids' => IdBinding::values($ids), 'version' => IdBinding::value(Defaults::LIVE_VERSION)],
            ['ids' => IdBinding::listType(), 'version' => IdBinding::type()]
        );

        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['product_id']][(string) $row['language_id']] = $row;
        }

        return $result;
    }

    /**
     * Rows grouped by the selected `owner` column, `:ids` and (when used) `:version` are bound.
     *
     * @param list<string> $ids
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function grouped(string $sql, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $params = ['ids' => IdBinding::values($ids)];
        $types = ['ids' => IdBinding::listType()];
        if (str_contains($sql, ':version')) {
            $params['version'] = IdBinding::value(Defaults::LIVE_VERSION);
            $types['version'] = IdBinding::type();
        }

        $result = [];
        foreach ($this->connection->fetchAllAssociative($sql, $params, $types) as $row) {
            $result[(string) $row['owner']][] = $row;
        }

        return $result;
    }

    /**
     * Rows keyed by the selected `id` column.
     *
     * @param list<string> $ids
     *
     * @return array<string, array<string, mixed>>
     */
    private function byId(string $sql, array $ids, bool $versioned): array
    {
        if ($ids === []) {
            return [];
        }

        $params = ['ids' => IdBinding::values($ids)];
        $types = ['ids' => IdBinding::listType()];
        if ($versioned) {
            $params['version'] = IdBinding::value(Defaults::LIVE_VERSION);
            $types['version'] = IdBinding::type();
        }

        $result = [];
        foreach ($this->connection->fetchAllAssociative($sql, $params, $types) as $row) {
            $result[(string) $row['id']] = $row;
        }

        return $result;
    }

    /**
     * Entities with translated fields as language maps: `{id, <field>: {<languageId>: value}, <plain column>: value}`.
     *
     * @param array<string, string> $translated translation column => document key
     * @param list<string> $plain columns of the entity table, kept with their name
     * @param list<string> $ids
     *
     * @return array<string, array<string, mixed>>
     */
    private function translatedEntities(string $table, string $translationTable, string $foreignKey, array $translated, array $plain, array $ids, bool $versioned): array
    {
        if ($ids === []) {
            return [];
        }

        $entities = $this->byId(
            \sprintf(
                'SELECT %s AS id%s FROM %s WHERE id IN (:ids)%s',
                IdBinding::text('id'),
                implode('', array_map(static fn (string $column): string => ', ' . $column, $plain)),
                $table,
                $versioned ? ' AND version_id = :version' : ''
            ),
            $ids,
            $versioned
        );

        $translationRows = $this->grouped(
            \sprintf(
                'SELECT %s AS owner, %s AS language_id, %s FROM %s WHERE %s IN (:ids)%s',
                IdBinding::text($foreignKey),
                IdBinding::text('language_id'),
                implode(', ', array_keys($translated)),
                $translationTable,
                $foreignKey,
                $versioned ? \sprintf(' AND %s_version_id = :version', $table) : ''
            ),
            array_keys($entities)
        );

        $chains = $this->scope !== null ? $this->scope->languageChains : [];
        $result = [];
        foreach ($entities as $id => $entity) {
            $byLanguage = [];
            foreach ($translationRows[$id] ?? [] as $row) {
                $byLanguage[(string) $row['language_id']] = $row;
            }

            /** @var array<string, mixed> $item */
            $item = ['id' => $id];
            foreach ($translated as $column => $key) {
                $values = [];
                foreach ($chains as $languageId => $chain) {
                    $values[$languageId] = self::translated($chain, $column, $byLanguage, []);
                }
                $item[$key] = $values;
            }
            foreach ($plain as $column) {
                $item[$column] = $entity[$column];
            }
            $result[$id] = $item;
        }

        return $result;
    }

    /**
     * @param list<string> $ids
     *
     * @return array<string, array<string, mixed>>
     */
    private function manufacturers(array $ids): array
    {
        $manufacturers = $this->translatedEntities(
            'product_manufacturer',
            'product_manufacturer_translation',
            'product_manufacturer_id',
            ['name' => 'name', 'link' => 'link'],
            [],
            $ids,
            true
        );
        if ($manufacturers === []) {
            return [];
        }

        $mediaIds = $this->byId(
            \sprintf('SELECT %s AS id, %s AS media_id FROM product_manufacturer WHERE id IN (:ids) AND version_id = :version', IdBinding::text('id'), IdBinding::text('media_id')),
            array_keys($manufacturers),
            true
        );
        foreach ($manufacturers as $id => $manufacturer) {
            $manufacturers[$id] = [
                'id' => $id,
                'name' => $manufacturer['name'],
                'link' => $manufacturer['link'],
                'mediaId' => $mediaIds[$id]['media_id'] ?? null,
            ];
        }

        return $manufacturers;
    }

    /**
     * Property group options with their group, the layout of {@see ProductDocument::$properties}.
     *
     * @param list<string> $ids
     *
     * @return array<string, array<string, mixed>>
     */
    private function options(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $options = $this->translatedEntities('property_group_option', 'property_group_option_translation', 'property_group_option_id', ['name' => 'name', 'position' => 'position'], [], $ids, false);
        $plain = $this->byId(
            \sprintf(
                'SELECT %s AS id, %s AS group_id, color_hex_code, %s AS media_id FROM property_group_option WHERE id IN (:ids)',
                IdBinding::text('id'),
                IdBinding::text('property_group_id'),
                IdBinding::text('media_id')
            ),
            $ids,
            false
        );
        $groupIds = array_values(array_unique(array_column($plain, 'group_id')));
        $groups = $this->translatedEntities(
            'property_group',
            'property_group_translation',
            'property_group_id',
            ['name' => 'name', 'position' => 'position'],
            ['display_type', 'sorting_type', 'filterable', 'visible_on_product_detail_page'],
            $groupIds,
            false
        );

        $system = Defaults::LANGUAGE_SYSTEM;
        $result = [];
        foreach ($options as $id => $option) {
            $row = $plain[$id];
            $group = $groups[$row['group_id']] ?? null;
            $result[$id] = [
                'id' => $id,
                'groupId' => $row['group_id'],
                'name' => $option['name'],
                'position' => self::intOrNull($option['position'][$system] ?? null),
                'colorHexCode' => $row['color_hex_code'],
                'mediaId' => $row['media_id'],
                'group' => $group === null ? null : [
                    'id' => $group['id'],
                    'name' => $group['name'],
                    'displayType' => $group['display_type'],
                    'sortingType' => $group['sorting_type'],
                    'filterable' => (bool) $group['filterable'],
                    'visibleOnProductDetailPage' => (bool) $group['visible_on_product_detail_page'],
                    'position' => self::intOrNull($group['position'][$system] ?? null),
                ],
            ];
        }

        return $result;
    }

    /**
     * @param list<array<string, mixed>> $links rows with `option_id`
     * @param array<string, array<string, mixed>> $options
     *
     * @return list<array<string, mixed>>
     */
    private static function optionList(array $links, array $options): array
    {
        $list = [];
        foreach ($links as $link) {
            $option = $options[(string) $link['option_id']] ?? null;
            if ($option !== null) {
                $list[] = $option;
            }
        }
        usort($list, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);

        return $list;
    }

    /**
     * @param array<string, list<array<string, mixed>>> $grouped
     *
     * @return list<array<string, mixed>>
     */
    private static function ownOrParent(array $grouped, string $id, ?string $parentId): array
    {
        $own = $grouped[$id] ?? [];
        if ($own !== [] || $parentId === null) {
            return $own;
        }

        return $grouped[$parentId] ?? [];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{id: string, mediaId: ?string, position: int, path: ?string}
     */
    private static function media(array $row): array
    {
        return ['id' => (string) $row['id'], 'mediaId' => $row['media_id'], 'position' => (int) $row['position'], 'path' => $row['path']];
    }

    /**
     * @param non-empty-list<string> $chain
     * @param array<string, array<string, mixed>> $own language id => translation row of the product
     * @param array<string, array<string, mixed>> $parent the same of the parent
     */
    private static function translated(array $chain, string $column, array $own, array $parent): mixed
    {
        foreach ($chain as $languageId) {
            $value = $own[$languageId][$column] ?? $parent[$languageId][$column] ?? null;
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Merged like `EntityHydrator::customFields()`: the first entry of the chain wins per key, `null` values are skipped.
     *
     * @param non-empty-list<string> $chain
     * @param array<string, array<string, mixed>> $own
     * @param array<string, array<string, mixed>> $parent
     *
     * @return array<string, mixed>
     */
    private static function customFields(array $chain, array $own, array $parent): array
    {
        $values = [];
        foreach ($chain as $languageId) {
            $values[] = $own[$languageId]['custom_fields'] ?? null;
            $values[] = $parent[$languageId]['custom_fields'] ?? null;
        }

        $merged = [];
        foreach (array_reverse($values) as $json) {
            $decoded = self::json($json);
            foreach ($decoded ?? [] as $key => $value) {
                if ($value !== null) {
                    $merged[$key] = $value;
                }
            }
        }

        return $merged;
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private static function priceList(mixed $json): ?array
    {
        return Normalizer::priceList(self::json($json));
    }

    /**
     * @return array<mixed>|null
     */
    private static function json(mixed $json): ?array
    {
        if (!\is_string($json) || $json === '') {
            return null;
        }

        $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        return \is_array($decoded) ? $decoded : null;
    }

    private static function value(mixed $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'bool' => (bool) $value,
            'int' => (int) $value,
            'float' => (float) $value,
            'json' => Normalizer::listingConfig(self::json($value)),
            'list' => array_values(array_map(strval(...), self::json($value) ?? [])),
            'date' => Normalizer::date($value),
            default => (string) $value,
        };
    }

    private static function intOrNull(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    /**
     * @param array<string, array<string, mixed>> $effective
     *
     * @return list<string>
     */
    private static function column(array $effective, string $field): array
    {
        return array_values(array_unique(array_filter(array_column($effective, $field))));
    }
}

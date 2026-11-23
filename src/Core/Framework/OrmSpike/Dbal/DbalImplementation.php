<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\Dbal;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Adapter\Database\Dialect\IdBinding;
use Shopware\Core\Framework\Adapter\Database\Dialect\SqlDialect;
use Shopware\Core\Framework\Adapter\Database\Dialect\SqlDialectFactory;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Indexing\EntityIndexerRegistry;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\OrmSpike\Fair\WriteContract;
use Shopware\Core\Framework\OrmSpike\Fair\WrittenEvents;
use Shopware\Core\Framework\OrmSpike\Fair\WrittenProduct;
use Shopware\Core\Framework\OrmSpike\Harness\Implementation;
use Shopware\Core\Framework\OrmSpike\Harness\Params;
use Shopware\Core\Framework\OrmSpike\Harness\ResultContract;
use Shopware\Core\Framework\OrmSpike\Harness\ScenarioNotSupported;
use Shopware\Core\Framework\OrmSpike\Harness\WriteSet;

/**
 * Reference implementation 2: hand-written SQL on the DBAL connection, the "no ORM" variant (lower bound for time, upper bound for code).
 *
 * One statement per read where possible. Translation fallback is a `COALESCE` over four joins (own de, parent de, own en, parent en),
 * inheritance is a `COALESCE` over the parent join, the total count is a `COUNT(*) OVER()` window. Ids and the database specific parts
 * go through {@see IdBinding} and the {@see SqlDialect}. All reads are storefront reads like in the DAL implementation: active (inherited) and
 * visible (visibility >= 10 in the sales channel). Visibility and categories of a variant are read from its parent: the data set has no variant
 * with own visibility rows (the DAL decides per row whether the variant has own rows, this code does not).
 *
 * `--option full=1` adds what the DAL does before and after a write: plain checks with the rules of the DAL field serializers, the write
 * protection check and an `EntityWrittenContainerEvent` for the create and for the stock update (docs/35).
 *
 * @internal
 */
#[Package('framework')]
final class DbalImplementation implements Implementation
{
    private ?SqlDialect $dialect = null;

    private bool $full = false;

    public function __construct(
        private readonly Connection $connection,
        private readonly WriteContract $contract,
        private readonly WrittenEvents $events,
    ) {
    }

    public static function key(): string
    {
        return 'dbal';
    }

    public function configure(array $options): void
    {
        $this->full = ($options['full'] ?? '0') === '1';
    }

    public function s1Listing(Params $params): array
    {
        [$from, $binds, $types] = $this->from($params);
        $binds['cat'] = IdBinding::value($params->categoryId);
        $types['cat'] = IdBinding::type();

        $rows = $this->connection->fetchAllAssociative(
            $this->select($params, true) . $from . $this->available()
            . ' AND EXISTS (SELECT 1 FROM product_category_tree c WHERE c.product_id = p.id AND c.product_version_id = p.version_id AND c.category_id = :cat)'
            . ' ORDER BY ' . $this->name() . ', p.product_number LIMIT ' . $params->limit . ' OFFSET ' . (($params->page - 1) * $params->limit),
            $binds,
            $types
        );

        return $this->listing($rows, true);
    }

    public function s2Detail(Params $params): array
    {
        [$from, $binds, $types] = $this->from($params);
        $binds['id'] = IdBinding::value($params->variantId);
        $types['id'] = IdBinding::type();

        $row = $this->connection->fetchAssociative(
            $this->select($params, false) . ', COALESCE(t1.description, t2.description, t3.description, t4.description) AS description, ' . IdBinding::text('COALESCE(p.product_manufacturer_id, pp.product_manufacturer_id)') . ' AS manufacturer_id, '
            . IdBinding::text('p.parent_id') . ' AS parent_id'
            . $from . $this->available() . ' AND p.id = :id',
            $binds,
            $types
        );
        if ($row === false) {
            throw new \RuntimeException('S2 variant not found or not visible: ' . $params->variantId);
        }

        $ids = [IdBinding::value($params->variantId)];
        if ($row['parent_id'] !== null) {
            $ids[] = IdBinding::value((string) $row['parent_id']);
        }
        $own = strtolower($params->variantId);
        $inList = ['ids' => $ids, 'v' => IdBinding::value($params->liveVersionId)];
        $inTypes = ['ids' => IdBinding::listType(), 'v' => IdBinding::type()];

        $prices = $this->preferOwn($this->connection->fetchAllAssociative(
            'SELECT ' . IdBinding::text('product_id') . ' AS owner, quantity_start, quantity_end, ' . $this->priceValue('price', $params) . ' AS price FROM product_price WHERE product_id IN (:ids) AND product_version_id = :v ORDER BY quantity_start',
            $inList,
            $inTypes
        ), $own);

        $options = $this->preferOwn($this->connection->fetchAllAssociative(
            'SELECT ' . IdBinding::text('po.product_id') . ' AS owner, COALESCE(gt1.name, gt2.name) AS group_name, COALESCE(ot1.name, ot2.name) AS option_name'
            . ' FROM product_option po INNER JOIN property_group_option o ON o.id = po.property_group_option_id'
            . ' LEFT JOIN property_group_option_translation ot1 ON ot1.property_group_option_id = o.id AND ot1.language_id = :de'
            . ' LEFT JOIN property_group_option_translation ot2 ON ot2.property_group_option_id = o.id AND ot2.language_id = :en'
            . ' LEFT JOIN property_group_translation gt1 ON gt1.property_group_id = o.property_group_id AND gt1.language_id = :de'
            . ' LEFT JOIN property_group_translation gt2 ON gt2.property_group_id = o.property_group_id AND gt2.language_id = :en'
            . ' WHERE po.product_id IN (:ids) AND po.product_version_id = :v',
            $inList + ['de' => IdBinding::value($params->languageId), 'en' => IdBinding::value($params->systemLanguageId)],
            $inTypes + ['de' => IdBinding::type(), 'en' => IdBinding::type()]
        ), $own);

        $categories = $this->preferOwn($this->connection->fetchAllAssociative(
            'SELECT ' . IdBinding::text('product_id') . ' AS owner, ' . IdBinding::text('category_id') . ' AS category_id FROM product_category WHERE product_id IN (:ids) AND product_version_id = :v',
            $inList,
            $inTypes
        ), $own);

        $optionList = array_map(static fn (array $o): array => ['group' => (string) $o['group_name'], 'name' => (string) $o['option_name']], $options);
        usort($optionList, static fn (array $a, array $b): int => [$a['group'], $a['name']] <=> [$b['group'], $b['name']]);
        $categoryList = array_map(static fn (array $c): string => strtolower((string) $c['category_id']), $categories);
        sort($categoryList);

        return [
            'id' => strtolower((string) $row['id']),
            'productNumber' => $row['product_number'],
            'name' => $row['name'],
            'description' => $row['description'],
            'manufacturerId' => $row['manufacturer_id'] === null ? null : strtolower((string) $row['manufacturer_id']),
            'manufacturer' => $row['manufacturer'],
            'cover' => $row['cover'],
            'price' => $this->decimal($row['price']),
            'prices' => array_map(fn (array $p): array => [
                'quantityStart' => (int) $p['quantity_start'],
                'quantityEnd' => $p['quantity_end'] === null ? null : (int) $p['quantity_end'],
                'price' => $this->decimal($p['price']),
            ], $prices),
            'options' => $optionList,
            'categories' => $categoryList,
        ];
    }

    public function s3Search(Params $params): array
    {
        [$from, $binds, $types] = $this->from($params);
        $binds['term'] = $this->dialect()->caseInsensitiveValue('%' . $params->searchTerm . '%');
        $types['term'] = ParameterType::STRING;
        $binds['mf'] = IdBinding::values($params->manufacturerIds);
        $types['mf'] = IdBinding::listType();

        $where = $this->available()
            . ' AND ' . $this->dialect()->caseInsensitive($this->name()) . ' LIKE :term'
            . ' AND COALESCE(p.product_manufacturer_id, pp.product_manufacturer_id) IN (:mf)';

        $rows = $this->connection->fetchAllAssociative(
            $this->select($params, true) . $from . $where . ' ORDER BY ' . $this->name() . ', p.product_number LIMIT ' . $params->limit,
            $binds,
            $types
        );

        $price = 'CAST(' . $this->priceValue('COALESCE(p.price, pp.price)', $params) . ' AS DECIMAL(20,4))';
        $stats = $this->connection->fetchAssociative('SELECT MIN(' . $price . ') AS min_price, MAX(' . $price . ') AS max_price' . $from . $where, $binds, $types);
        $terms = $this->connection->fetchAllAssociative(
            'SELECT ' . IdBinding::text('COALESCE(p.product_manufacturer_id, pp.product_manufacturer_id)') . ' AS manufacturer_id, COUNT(*) AS cnt' . $from . $where . ' GROUP BY COALESCE(p.product_manufacturer_id, pp.product_manufacturer_id)',
            $binds,
            $types
        );

        $manufacturers = [];
        foreach ($terms as $term) {
            $manufacturers[strtolower((string) $term['manufacturer_id'])] = (int) $term['cnt'];
        }
        ksort($manufacturers);

        $listing = $this->listing($rows, false);

        return $listing + [
            'aggregations' => [
                'minPrice' => $this->decimal($stats['min_price'] ?? null),
                'maxPrice' => $this->decimal($stats['max_price'] ?? null),
                'manufacturers' => $manufacturers,
            ],
        ];
    }

    public function s4Write(Params $params, WriteSet $set): void
    {
        $now = (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT);
        $version = IdBinding::value($params->liveVersionId);
        $idType = IdBinding::type();

        if ($this->full) {
            $context = Context::createDefaultContext();
            $context->addState(EntityIndexerRegistry::DISABLE_INDEXING);
            $written = $this->written($params, $set);
            // nothing has reached the database yet
            WriteContract::plain($written);
            foreach ($written as $p) {
                $this->contract->assertWritable('product', ['id' => $p->id, 'taxId' => $p->taxId, 'productNumber' => $p->productNumber, 'stock' => $p->stock, 'price' => $p->gross, 'active' => $p->active, 'createdAt' => $now], $context);
                foreach ($p->translations as $t) {
                    $this->contract->assertWritable('product_translation', ['languageId' => $t['languageId'], 'name' => $t['name'], 'createdAt' => $now], $context);
                }
                $this->contract->assertWritable('product', ['id' => $p->id, 'stock' => $p->newStock, 'updatedAt' => $now], $context);
                foreach ($p->visibilities as $v) {
                    $this->contract->assertWritable('product_visibility', ['id' => $v['id'], 'salesChannelId' => $v['salesChannelId'], 'visibility' => $v['visibility'], 'createdAt' => $now], $context);
                }
            }
        }

        $this->connection->transactional(function () use ($params, $set, $now, $version, $idType): void {
            $this->insert(
                'product',
                ['id', 'version_id', 'tax_id', 'product_number', 'stock', 'price', 'active', 'created_at'],
                array_map(static fn (array $p): array => [
                    [IdBinding::value($p['id']), $idType], [$version, $idType], [IdBinding::value($params->taxId), $idType],
                    [$p['productNumber'], ParameterType::STRING], [$p['stock'], ParameterType::INTEGER],
                    [json_encode(['c' . $params->currencyId => ['currencyId' => $params->currencyId, 'gross' => $p['gross'], 'net' => $p['net'], 'linked' => true]], \JSON_THROW_ON_ERROR), ParameterType::STRING],
                    [true, ParameterType::BOOLEAN], [$now, ParameterType::STRING],
                ], $set->products)
            );

            $translations = [];
            $categories = [];
            $visibilities = [];
            foreach ($set->products as $p) {
                foreach ([[$params->systemLanguageId, $p['nameEn']], [$params->languageId, $p['nameDe']]] as [$language, $name]) {
                    $translations[] = [[IdBinding::value($p['id']), $idType], [$version, $idType], [IdBinding::value($language), $idType], [$name, ParameterType::STRING], [$now, ParameterType::STRING]];
                }
                foreach ($p['categoryIds'] as $categoryId) {
                    $categories[] = [[IdBinding::value($p['id']), $idType], [$version, $idType], [IdBinding::value($categoryId), $idType], [$version, $idType]];
                }
                $visibilities[] = [
                    [IdBinding::value(md5('visibility' . $p['id'])), $idType], [IdBinding::value($p['id']), $idType], [$version, $idType],
                    [IdBinding::value($params->salesChannelId), $idType], [30, ParameterType::INTEGER], [$now, ParameterType::STRING],
                ];
            }
            $this->insert('product_translation', ['product_id', 'product_version_id', 'language_id', 'name', 'created_at'], $translations);
            $this->insert('product_category', ['product_id', 'product_version_id', 'category_id', 'category_version_id'], $categories);
            $this->insert('product_visibility', ['id', 'product_id', 'product_version_id', 'sales_channel_id', 'visibility', 'created_at'], $visibilities);

            // stock update of all 50 in one statement
            $cases = '';
            $binds = ['v' => $version, 'now' => $now];
            $types = ['v' => $idType, 'now' => ParameterType::STRING];
            $ids = [];
            foreach ($set->products as $i => $p) {
                $cases .= ' WHEN id = :i' . $i . ' THEN ' . (int) $p['newStock'];
                $binds['i' . $i] = IdBinding::value($p['id']);
                $types['i' . $i] = $idType;
                $ids[] = ':i' . $i;
            }
            $this->connection->executeStatement(
                'UPDATE product SET stock = CASE' . $cases . ' END, updated_at = :now WHERE version_id = :v AND id IN (' . implode(',', $ids) . ')',
                $binds,
                $types
            );
        });

        if ($this->full) {
            // one transaction for both writes, so the two events follow the commit
            $this->events->created($written, $params, $context);
            $this->events->stockUpdated(array_map(static fn (WrittenProduct $p, array $row): WrittenProduct => new WrittenProduct(
                $p->id,
                $p->productNumber,
                $row['newStock'],
                $row['newStock'],
                $p->taxId,
                $p->active,
                $p->currencyId,
                $p->gross,
                $p->net,
                $p->translations,
                $p->categoryIds,
                $p->visibilities
            ), $written, $set->products), $params, $context);
        }
    }

    public function s5CriteriaFromJson(Params $params): array
    {
        $json = $params->criteriaJson;
        [$from, $binds, $types] = $this->from($params);
        $fields = [
            'active' => 'COALESCE(p.active, pp.active)',
            'stock' => 'p.stock',
            'name' => $this->name(),
            'productNumber' => 'p.product_number',
        ];

        $where = $this->available();
        foreach ($json['filter'] ?? [] as $i => $filter) {
            $field = $fields[$filter['field'] ?? ''] ?? throw new \InvalidArgumentException('Field not allowed: ' . ($filter['field'] ?? ''));
            $key = 'f' . $i;
            switch ($filter['type'] ?? '') {
                case 'equals':
                    $where .= ' AND ' . $field . ' = :' . $key;
                    $binds[$key] = $filter['value'];
                    $types[$key] = \is_bool($filter['value']) ? ParameterType::BOOLEAN : (\is_int($filter['value']) ? ParameterType::INTEGER : ParameterType::STRING);
                    break;
                case 'contains':
                    $where .= ' AND ' . $this->dialect()->caseInsensitive($field) . ' LIKE :' . $key;
                    $binds[$key] = $this->dialect()->caseInsensitiveValue('%' . $filter['value'] . '%');
                    $types[$key] = ParameterType::STRING;
                    break;
                case 'range':
                    foreach (['gte' => '>=', 'gt' => '>', 'lte' => '<=', 'lt' => '<'] as $name => $operator) {
                        if (isset($filter['parameters'][$name])) {
                            $where .= ' AND ' . $field . ' ' . $operator . ' :' . $key . $name;
                            $binds[$key . $name] = (int) $filter['parameters'][$name];
                            $types[$key . $name] = ParameterType::INTEGER;
                        }
                    }
                    break;
                default:
                    throw new ScenarioNotSupported('Filter type not supported by the DBAL translator: ' . ($filter['type'] ?? ''));
            }
        }

        $order = [];
        foreach ($json['sort'] ?? [] as $sort) {
            $field = $fields[$sort['field'] ?? ''] ?? throw new \InvalidArgumentException('Field not allowed: ' . ($sort['field'] ?? ''));
            $order[] = $field . (strtoupper((string) ($sort['order'] ?? 'ASC')) === 'DESC' ? ' DESC' : ' ASC');
        }

        $rows = $this->connection->fetchAllAssociative(
            $this->select($params, true) . $from . $where . ($order === [] ? '' : ' ORDER BY ' . implode(', ', $order)) . ' LIMIT ' . (int) ($json['limit'] ?? 25),
            $binds,
            $types
        );

        $result = $this->listing($rows, false);

        return [
            'total' => $result['total'],
            'items' => array_map(static fn (array $i): array => ['id' => $i['id'], 'productNumber' => $i['productNumber'], 'manufacturer' => $i['manufacturer']], $result['items']),
        ];
    }

    /**
     * What the transaction writes, as the events need it.
     *
     * @return list<WrittenProduct>
     */
    private function written(Params $params, WriteSet $set): array
    {
        return array_map(static fn (array $p): WrittenProduct => new WrittenProduct(
            $p['id'],
            $p['productNumber'],
            $p['stock'],
            $p['newStock'],
            $params->taxId,
            true,
            $params->currencyId,
            $p['gross'],
            $p['net'],
            [['languageId' => $params->systemLanguageId, 'name' => $p['nameEn']], ['languageId' => $params->languageId, 'name' => $p['nameDe']]],
            $p['categoryIds'],
            [['id' => md5('visibility' . $p['id']), 'salesChannelId' => $params->salesChannelId, 'visibility' => 30]]
        ), $set->products);
    }

    private function dialect(): SqlDialect
    {
        return $this->dialect ??= SqlDialectFactory::getDefault();
    }

    private function name(): string
    {
        return 'COALESCE(t1.name, t2.name, t3.name, t4.name)';
    }

    /**
     * SELECT list shared by the listing, detail, search and criteria reads: id, number, translated name, manufacturer name, cover path, price (and the total).
     */
    private function select(Params $params, bool $withTotal): string
    {
        return 'SELECT ' . IdBinding::text('p.id') . ' AS id, p.product_number, ' . $this->name() . ' AS name, COALESCE(mt1.name, mt2.name) AS manufacturer, m.path AS cover, '
            . $this->priceValue('COALESCE(p.price, pp.price)', $params) . ' AS price'
            . ($withTotal ? ', COUNT(*) OVER() AS total' : '');
    }

    /**
     * @return array{0: string, 1: array<string, mixed>, 2: array<string, ParameterType|ArrayParameterType>}
     */
    private function from(Params $params): array
    {
        $sql = ' FROM product p'
            . ' LEFT JOIN product pp ON pp.id = p.parent_id AND pp.version_id = p.parent_version_id'
            . ' LEFT JOIN product_translation t1 ON t1.product_id = p.id AND t1.product_version_id = p.version_id AND t1.language_id = :de'
            . ' LEFT JOIN product_translation t2 ON t2.product_id = pp.id AND t2.product_version_id = pp.version_id AND t2.language_id = :de'
            . ' LEFT JOIN product_translation t3 ON t3.product_id = p.id AND t3.product_version_id = p.version_id AND t3.language_id = :en'
            . ' LEFT JOIN product_translation t4 ON t4.product_id = pp.id AND t4.product_version_id = pp.version_id AND t4.language_id = :en'
            . ' LEFT JOIN product_manufacturer_translation mt1 ON mt1.product_manufacturer_id = COALESCE(p.product_manufacturer_id, pp.product_manufacturer_id) AND mt1.language_id = :de'
            . ' LEFT JOIN product_manufacturer_translation mt2 ON mt2.product_manufacturer_id = COALESCE(p.product_manufacturer_id, pp.product_manufacturer_id) AND mt2.language_id = :en'
            . ' LEFT JOIN product_media pm ON pm.id = COALESCE(p.product_media_id, pp.product_media_id) AND pm.version_id = p.version_id'
            . ' LEFT JOIN media m ON m.id = pm.media_id';

        return [
            $sql,
            [
                'de' => IdBinding::value($params->languageId),
                'en' => IdBinding::value($params->systemLanguageId),
                'v' => IdBinding::value($params->liveVersionId),
                'sc' => IdBinding::value($params->salesChannelId),
            ],
            ['de' => IdBinding::type(), 'en' => IdBinding::type(), 'v' => IdBinding::type(), 'sc' => IdBinding::type()],
        ];
    }

    /**
     * Live version, active (inherited) and visible in the sales channel (visibility of the parent for a variant), like `ProductAvailableFilter`.
     */
    private function available(): string
    {
        return ' WHERE p.version_id = :v AND COALESCE(p.active, pp.active) = 1'
            . ' AND EXISTS (SELECT 1 FROM product_visibility v WHERE v.product_id = COALESCE(p.parent_id, p.id) AND v.product_version_id = p.version_id AND v.sales_channel_id = :sc AND v.visibility >= 10)';
    }

    private function priceValue(string $jsonColumnSql, Params $params): string
    {
        return $this->dialect()->jsonExtractNumber($jsonColumnSql, '$.c' . $params->currencyId . '.gross');
    }

    private function decimal(mixed $value): ?string
    {
        return ResultContract::price($value === null ? null : (float) $value);
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array{total: int, items: list<array<string, mixed>>}
     */
    private function listing(array $rows, bool $withCover): array
    {
        $items = [];
        foreach ($rows as $row) {
            $item = [
                'id' => strtolower((string) $row['id']),
                'productNumber' => $row['product_number'],
                'name' => $row['name'],
                'manufacturer' => $row['manufacturer'],
                'price' => $this->decimal($row['price']),
            ];
            if ($withCover) {
                $item['cover'] = $row['cover'];
            }
            $items[] = $item;
        }

        return ['total' => (int) ($rows[0]['total'] ?? 0), 'items' => $items];
    }

    /**
     * To-many inheritance: the rows of the variant if it has any, otherwise the rows of its parent.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function preferOwn(array $rows, string $ownId): array
    {
        $own = array_values(array_filter($rows, static fn (array $r): bool => strtolower((string) $r['owner']) === $ownId));

        return $own !== [] ? $own : $rows;
    }

    /**
     * Multi-row insert: one statement, one placeholder per value.
     *
     * @param list<string> $columns
     * @param list<list<array{0: mixed, 1: ParameterType}>> $rows
     */
    private function insert(string $table, array $columns, array $rows): void
    {
        $binds = [];
        $types = [];
        $tuples = [];
        foreach ($rows as $r => $row) {
            $placeholders = [];
            foreach ($row as $c => [$value, $type]) {
                $name = 'p' . $r . '_' . $c;
                $placeholders[] = ':' . $name;
                $binds[$name] = $value;
                $types[$name] = $type;
            }
            $tuples[] = '(' . implode(',', $placeholders) . ')';
        }

        $this->connection->executeStatement('INSERT INTO ' . $table . ' (' . implode(',', $columns) . ') VALUES ' . implode(',', $tuples), $binds, $types);
    }
}

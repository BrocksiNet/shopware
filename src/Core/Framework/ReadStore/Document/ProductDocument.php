<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Document;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\ReadStore\Port\Schema\DocumentSchema;
use Shopware\Core\Framework\ReadStore\Port\Schema\Field;
use Shopware\Core\Framework\ReadStore\Port\Schema\FieldScope;
use Shopware\Core\Framework\ReadStore\Port\Schema\FieldType;

/**
 * The product document every read store keeps, one per product row (parents and variants), Q2 option (a): all languages, currencies,
 * rule prices and sales channel visibilities in one document, the context picks its keys at query time.
 *
 * Build-time rules (the builders of milestone 2 implement them, the contract fixtures follow them):
 * - ids are lower case hex, date times `Y-m-d H:i:s.v` (UTC);
 * - variants are complete: every inherited field holds the parent's value when the variant has none (to-many associations too:
 *   a variant without own `prices`, `categoryIds`, `properties` gets the parent's);
 * - a {@see FieldScope::LANGUAGE} map has an entry for every language of the shop, already resolved with that language's fallback chain
 *   and parent inheritance: `COALESCE(own language, parent language, own parent-language, ..., own system, parent system)`;
 * - `priceGross`/`priceNet` per currency: `IFNULL(COALESCE(own currency, own default currency * factor), COALESCE(parent ..., ...))`,
 *   rounded with the item rounding of that currency, like {@see \Shopware\Core\Framework\DataAbstractionLayer\Dbal\FieldAccessorBuilder\PriceFieldAccessorBuilder};
 * - `cheapestPrice` per rule key (`default` or rule id) and currency, from the own `cheapest_price_accessor`, the parent's when the own is
 *   empty (the field is inherited), with the default currency times the factor for missing currencies, rounded with the item rounding of each
 *   currency like {@see \Shopware\Core\Content\Product\DataAbstractionLayer\CheapestPrice\CheapestPriceAccessorBuilder};
 *   `percentage` is stored inverted (`100 - percentage`), as the accessor builder returns it;
 * - `visibilities` per sales channel id: the visibility value (10, 20, 30), the parent's when the variant has none;
 * - `searchKeywords` per language: `{keyword: ranking}` from `product_search_keyword`;
 * - `extensions`: plugin data added by `DocumentExtender`s, keyed by the extender's name.
 *
 * A change of the layout raises {@see self::SCHEMA_VERSION}; a store with documents of another version is rebuilt.
 *
 * @internal
 */
#[Package('framework')]
final class ProductDocument
{
    public const SCHEMA_VERSION = 1;

    #[Field(FieldType::ID)]
    public string $id = '';

    #[Field(FieldType::ID)]
    public ?string $parentId = null;

    #[Field(FieldType::INT, queryable: false)]
    public int $schemaVersion = self::SCHEMA_VERSION;

    #[Field(FieldType::STRING)]
    public string $productNumber = '';

    /**
     * The collapse key of storefront listings (`product.display_group`), `null` for parents with variants.
     */
    #[Field(FieldType::ID)]
    public ?string $displayGroup = null;

    /**
     * Of the parent: `{displayParent, mainVariantId, configuratorGroupConfig}`, used by the listing preview resolution.
     *
     * @var array<string, mixed>|null
     */
    #[Field(FieldType::OBJECT, queryable: false)]
    public ?array $variantListingConfig = null;

    #[Field(FieldType::INT)]
    public int $childCount = 0;

    #[Field(FieldType::BOOL)]
    public ?bool $active = null;

    #[Field(FieldType::BOOL)]
    public ?bool $available = null;

    #[Field(FieldType::INT)]
    public int $stock = 0;

    #[Field(FieldType::INT)]
    public ?int $availableStock = null;

    #[Field(FieldType::BOOL)]
    public ?bool $isCloseout = null;

    #[Field(FieldType::INT)]
    public ?int $minPurchase = null;

    #[Field(FieldType::INT)]
    public ?int $maxPurchase = null;

    #[Field(FieldType::INT)]
    public ?int $purchaseSteps = null;

    #[Field(FieldType::FLOAT, queryable: false)]
    public ?float $purchaseUnit = null;

    #[Field(FieldType::FLOAT, queryable: false)]
    public ?float $referenceUnit = null;

    #[Field(FieldType::INT, queryable: false)]
    public ?int $restockTime = null;

    #[Field(FieldType::STRING)]
    public ?string $ean = null;

    #[Field(FieldType::STRING)]
    public ?string $manufacturerNumber = null;

    #[Field(FieldType::FLOAT)]
    public ?float $weight = null;

    #[Field(FieldType::FLOAT)]
    public ?float $ratingAverage = null;

    #[Field(FieldType::BOOL)]
    public ?bool $markAsTopseller = null;

    #[Field(FieldType::BOOL)]
    public ?bool $shippingFree = null;

    #[Field(FieldType::INT)]
    public int $sales = 0;

    #[Field(FieldType::DATETIME)]
    public ?string $releaseDate = null;

    #[Field(FieldType::DATETIME)]
    public ?string $createdAt = null;

    #[Field(FieldType::DATETIME)]
    public ?string $updatedAt = null;

    /**
     * @var array<string, string|null>
     */
    #[Field(FieldType::TEXT, FieldScope::LANGUAGE)]
    public array $name = [];

    /**
     * @var array<string, string|null>
     */
    #[Field(FieldType::TEXT, FieldScope::LANGUAGE)]
    public array $description = [];

    /**
     * @var array<string, string|null>
     */
    #[Field(FieldType::TEXT, FieldScope::LANGUAGE, queryable: false)]
    public array $metaTitle = [];

    /**
     * @var array<string, string|null>
     */
    #[Field(FieldType::TEXT, FieldScope::LANGUAGE, queryable: false)]
    public array $metaDescription = [];

    /**
     * @var array<string, string|null>
     */
    #[Field(FieldType::TEXT, FieldScope::LANGUAGE, queryable: false)]
    public array $keywords = [];

    /**
     * @var array<string, string|null>
     */
    #[Field(FieldType::TEXT, FieldScope::LANGUAGE, queryable: false)]
    public array $packUnit = [];

    /**
     * @var array<string, string|null>
     */
    #[Field(FieldType::TEXT, FieldScope::LANGUAGE, queryable: false)]
    public array $packUnitPlural = [];

    /**
     * Per language the merged custom fields, queryable as `customFields.<name>`.
     *
     * @var array<string, array<string, mixed>|null>
     */
    #[Field(FieldType::OBJECT, FieldScope::LANGUAGE)]
    public array $customFields = [];

    #[Field(FieldType::ID)]
    public ?string $manufacturerId = null;

    /**
     * `{id, name: {<languageId>: string}, link, mediaId}`
     *
     * @var array<string, mixed>|null
     */
    #[Field(FieldType::OBJECT, queryable: false)]
    public ?array $manufacturer = null;

    #[Field(FieldType::ID)]
    public ?string $taxId = null;

    /**
     * `{id, taxRate, name}`
     *
     * @var array<string, mixed>|null
     */
    #[Field(FieldType::OBJECT, queryable: false)]
    public ?array $tax = null;

    #[Field(FieldType::ID)]
    public ?string $unitId = null;

    /**
     * `{id, name: {<languageId>: string}, shortCode: {<languageId>: string}}`
     *
     * @var array<string, mixed>|null
     */
    #[Field(FieldType::OBJECT, queryable: false)]
    public ?array $unit = null;

    #[Field(FieldType::ID)]
    public ?string $deliveryTimeId = null;

    /**
     * `{id, name: {<languageId>: string}, min, max, unit}`
     *
     * @var array<string, mixed>|null
     */
    #[Field(FieldType::OBJECT, queryable: false)]
    public ?array $deliveryTime = null;

    #[Field(FieldType::ID)]
    public ?string $coverId = null;

    /**
     * `{id, mediaId, position, path}`, `id` is the product media id.
     *
     * @var array<string, mixed>|null
     */
    #[Field(FieldType::OBJECT, queryable: false)]
    public ?array $cover = null;

    /**
     * `[{id, mediaId, position, path}]` sorted by position.
     *
     * @var list<array<string, mixed>>
     */
    #[Field(FieldType::OBJECT_LIST, queryable: false)]
    public array $media = [];

    /**
     * The raw `product.price` (inherited): `[{currencyId, gross, net, linked, listPrice?, regulationPrice?, percentage?}]`.
     *
     * @var list<array<string, mixed>>|null
     */
    #[Field(FieldType::OBJECT_LIST, queryable: false)]
    public ?array $price = null;

    /**
     * Advanced prices (inherited): `[{id, ruleId, quantityStart, quantityEnd, price: <raw price list>}]` sorted by rule id, quantityStart.
     *
     * @var list<array<string, mixed>>
     */
    #[Field(FieldType::OBJECT_LIST, queryable: false)]
    public array $prices = [];

    /**
     * Query field `price.gross`: per currency id.
     *
     * @var array<string, float|null>
     */
    #[Field(FieldType::FLOAT, FieldScope::CURRENCY)]
    public array $priceGross = [];

    /**
     * Query field `price.net`: per currency id.
     *
     * @var array<string, float|null>
     */
    #[Field(FieldType::FLOAT, FieldScope::CURRENCY)]
    public array $priceNet = [];

    /**
     * @var array<string, array<string, array<string, mixed>>>
     */
    #[Field(FieldType::FLOAT, FieldScope::RULE_CURRENCY)]
    public array $cheapestPrice = [];

    /**
     * @var array<string, int>
     */
    #[Field(FieldType::INT, FieldScope::SALES_CHANNEL)]
    public array $visibilities = [];

    /**
     * Directly assigned categories (inherited).
     *
     * @var list<string>
     */
    #[Field(FieldType::LIST)]
    public array $categoryIds = [];

    /**
     * Assigned categories and all their parents (`product.category_tree`, the DAL `categoriesRo`).
     *
     * @var list<string>
     */
    #[Field(FieldType::LIST)]
    public array $categoryTree = [];

    /**
     * @var list<string>
     */
    #[Field(FieldType::LIST)]
    public array $streamIds = [];

    /**
     * @var list<string>
     */
    #[Field(FieldType::LIST)]
    public array $tagIds = [];

    /**
     * @var list<string>
     */
    #[Field(FieldType::LIST)]
    public array $propertyIds = [];

    /**
     * @var list<string>
     */
    #[Field(FieldType::LIST)]
    public array $optionIds = [];

    /**
     * `[{id, groupId, name: {<languageId>: string}, position, colorHexCode, mediaId, group: {id, name: {<languageId>: string}, displayType, sortingType, filterable, visibleOnProductDetailPage, position}}]`
     *
     * @var list<array<string, mixed>>
     */
    #[Field(FieldType::OBJECT_LIST, queryable: false)]
    public array $properties = [];

    /**
     * The variant options, same layout as `properties`.
     *
     * @var list<array<string, mixed>>
     */
    #[Field(FieldType::OBJECT_LIST, queryable: false)]
    public array $options = [];

    /**
     * @var list<string>
     */
    #[Field(FieldType::LIST)]
    public array $states = [];

    /**
     * Per language `{keyword: ranking}`, only {@see \Shopware\Core\Framework\ReadStore\Port\SearchAware} reads it.
     *
     * @var array<string, array<string, float>>
     */
    #[Field(FieldType::OBJECT, FieldScope::LANGUAGE, queryable: false)]
    public array $searchKeywords = [];

    /**
     * Plugin data from `DocumentExtender`s, queryable as `extensions.<name>.<key>`.
     *
     * @var array<string, mixed>
     */
    #[Field(FieldType::OBJECT)]
    public array $extensions = [];

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $document = new self();
        foreach (DocumentSchema::fields(self::class) as $name => $field) {
            if (\array_key_exists($name, $data)) {
                (new \ReflectionProperty(self::class, $name))->setValue($document, $data[$name]);
            }
        }

        return $document;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $values = get_object_vars($this);
        $data = [];
        foreach (array_keys(DocumentSchema::fields(self::class)) as $name) {
            $data[$name] = $values[$name];
        }

        return $data;
    }
}

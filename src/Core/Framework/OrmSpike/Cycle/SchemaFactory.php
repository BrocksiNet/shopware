<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\Cycle;

use Cycle\ORM\Parser\Typecast;
use Cycle\ORM\Relation;
use Cycle\ORM\SchemaInterface as S;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\OrmSpike\Cycle\Entity\Manufacturer;
use Shopware\Core\Framework\OrmSpike\Cycle\Entity\ManufacturerTranslation;
use Shopware\Core\Framework\OrmSpike\Cycle\Entity\Media;
use Shopware\Core\Framework\OrmSpike\Cycle\Entity\Product;
use Shopware\Core\Framework\OrmSpike\Cycle\Entity\ProductCategory;
use Shopware\Core\Framework\OrmSpike\Cycle\Entity\ProductCategoryTree;
use Shopware\Core\Framework\OrmSpike\Cycle\Entity\ProductMedia;
use Shopware\Core\Framework\OrmSpike\Cycle\Entity\ProductOption;
use Shopware\Core\Framework\OrmSpike\Cycle\Entity\ProductPrice;
use Shopware\Core\Framework\OrmSpike\Cycle\Entity\ProductTranslation;
use Shopware\Core\Framework\OrmSpike\Cycle\Entity\ProductVisibility;
use Shopware\Core\Framework\OrmSpike\Cycle\Entity\PropertyGroup;
use Shopware\Core\Framework\OrmSpike\Cycle\Entity\PropertyGroupOption;
use Shopware\Core\Framework\OrmSpike\Cycle\Entity\PropertyGroupOptionTranslation;
use Shopware\Core\Framework\OrmSpike\Cycle\Entity\PropertyGroupTranslation;

/**
 * The runtime mapping: a plain PHP array in the format of `Cycle\ORM\Schema`, no annotations, no schema compiler, no schema sync.
 * Keys of fields and relations are PHP property names, the right side of `columns` is the Shopware column.
 * Every id field has the rule `hexid` ({@see HexIdTypecast}).
 *
 * @internal
 */
#[Package('framework')]
final class SchemaFactory
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public static function build(): array
    {
        $hasMany = static fn (string $target, array $inner, array $outer, bool $cascade = true): array => [
            Relation::TYPE => Relation::HAS_MANY,
            Relation::TARGET => $target,
            Relation::SCHEMA => [Relation::CASCADE => $cascade, Relation::INNER_KEY => $inner, Relation::OUTER_KEY => $outer],
        ];
        $belongsTo = static fn (string $target, array $inner, array $outer): array => [
            Relation::TYPE => Relation::BELONGS_TO,
            Relation::TARGET => $target,
            Relation::SCHEMA => [Relation::CASCADE => false, Relation::NULLABLE => true, Relation::INNER_KEY => $inner, Relation::OUTER_KEY => $outer],
        ];

        return [
            'product' => self::entity(Product::class, 'product', ['id', 'versionId'], [
                'id' => 'id', 'versionId' => 'version_id', 'parentId' => 'parent_id', 'parentVersionId' => 'parent_version_id',
                'productNumber' => 'product_number', 'active' => 'active', 'stock' => 'stock', 'price' => 'price',
                'manufacturerId' => 'product_manufacturer_id', 'manufacturerVersionId' => 'product_manufacturer_version_id',
                'mediaId' => 'product_media_id', 'mediaVersionId' => 'product_media_version_id', 'taxId' => 'tax_id',
                'createdAt' => 'created_at', 'updatedAt' => 'updated_at',
            ], ['id', 'versionId', 'parentId', 'parentVersionId', 'manufacturerId', 'manufacturerVersionId', 'mediaId', 'mediaVersionId', 'taxId'], ['active' => 'int', 'stock' => 'int'], [
                'translations' => $hasMany('productTranslation', ['id', 'versionId'], ['productId', 'productVersionId']),
                // Cycle joins a relation once per path and alias, a second language needs a second relation to the same table
                'translationsAlt' => $hasMany('productTranslation', ['id', 'versionId'], ['productId', 'productVersionId'], false),
                'parent' => $belongsTo('product', ['parentId', 'parentVersionId'], ['id', 'versionId']),
                'manufacturer' => $belongsTo('manufacturer', ['manufacturerId', 'manufacturerVersionId'], ['id', 'versionId']),
                'cover' => $belongsTo('productMedia', ['mediaId', 'mediaVersionId'], ['id', 'versionId']),
                'prices' => $hasMany('productPrice', ['id', 'versionId'], ['productId', 'productVersionId'], false),
                'categories' => $hasMany('productCategory', ['id', 'versionId'], ['productId', 'productVersionId']),
                'categoryTree' => $hasMany('productCategoryTree', ['id', 'versionId'], ['productId', 'productVersionId'], false),
                'visibilities' => $hasMany('productVisibility', ['id', 'versionId'], ['productId', 'productVersionId']),
                'options' => $hasMany('productOption', ['id', 'versionId'], ['productId', 'productVersionId'], false),
            ]),
            'productTranslation' => self::entity(ProductTranslation::class, 'product_translation', ['productId', 'productVersionId', 'languageId'], [
                'productId' => 'product_id', 'productVersionId' => 'product_version_id', 'languageId' => 'language_id',
                'name' => 'name', 'description' => 'description', 'createdAt' => 'created_at',
            ], ['productId', 'productVersionId', 'languageId']),
            'manufacturer' => self::entity(Manufacturer::class, 'product_manufacturer', ['id', 'versionId'], [
                'id' => 'id', 'versionId' => 'version_id', 'createdAt' => 'created_at',
            ], ['id', 'versionId'], [], [
                'translations' => $hasMany('manufacturerTranslation', ['id', 'versionId'], ['manufacturerId', 'manufacturerVersionId'], false),
                'translationsAlt' => $hasMany('manufacturerTranslation', ['id', 'versionId'], ['manufacturerId', 'manufacturerVersionId'], false),
            ]),
            'manufacturerTranslation' => self::entity(ManufacturerTranslation::class, 'product_manufacturer_translation', ['manufacturerId', 'manufacturerVersionId', 'languageId'], [
                'manufacturerId' => 'product_manufacturer_id', 'manufacturerVersionId' => 'product_manufacturer_version_id', 'languageId' => 'language_id',
                'name' => 'name', 'createdAt' => 'created_at',
            ], ['manufacturerId', 'manufacturerVersionId', 'languageId']),
            'productMedia' => self::entity(ProductMedia::class, 'product_media', ['id', 'versionId'], [
                'id' => 'id', 'versionId' => 'version_id', 'productId' => 'product_id', 'productVersionId' => 'product_version_id',
                'mediaId' => 'media_id', 'position' => 'position', 'createdAt' => 'created_at',
            ], ['id', 'versionId', 'productId', 'productVersionId', 'mediaId'], ['position' => 'int'], [
                'media' => $belongsTo('media', ['mediaId'], ['id']),
            ]),
            'media' => self::entity(Media::class, 'media', ['id'], ['id' => 'id', 'path' => 'path'], ['id']),
            'productPrice' => self::entity(ProductPrice::class, 'product_price', ['id', 'versionId'], [
                'id' => 'id', 'versionId' => 'version_id', 'productId' => 'product_id', 'productVersionId' => 'product_version_id',
                'ruleId' => 'rule_id', 'price' => 'price', 'quantityStart' => 'quantity_start', 'quantityEnd' => 'quantity_end',
            ], ['id', 'versionId', 'productId', 'productVersionId', 'ruleId'], ['quantityStart' => 'int', 'quantityEnd' => 'int']),
            'productCategory' => self::entity(ProductCategory::class, 'product_category', ['productId', 'productVersionId', 'categoryId', 'categoryVersionId'], [
                'productId' => 'product_id', 'productVersionId' => 'product_version_id', 'categoryId' => 'category_id', 'categoryVersionId' => 'category_version_id',
            ], ['productId', 'productVersionId', 'categoryId', 'categoryVersionId']),
            'productCategoryTree' => self::entity(ProductCategoryTree::class, 'product_category_tree', ['productId', 'productVersionId', 'categoryId', 'categoryVersionId'], [
                'productId' => 'product_id', 'productVersionId' => 'product_version_id', 'categoryId' => 'category_id', 'categoryVersionId' => 'category_version_id',
            ], ['productId', 'productVersionId', 'categoryId', 'categoryVersionId']),
            'productVisibility' => self::entity(ProductVisibility::class, 'product_visibility', ['id'], [
                'id' => 'id', 'productId' => 'product_id', 'productVersionId' => 'product_version_id', 'salesChannelId' => 'sales_channel_id',
                'visibility' => 'visibility', 'createdAt' => 'created_at',
            ], ['id', 'productId', 'productVersionId', 'salesChannelId'], ['visibility' => 'int']),
            'productOption' => self::entity(ProductOption::class, 'product_option', ['productId', 'productVersionId', 'optionId'], [
                'productId' => 'product_id', 'productVersionId' => 'product_version_id', 'optionId' => 'property_group_option_id',
            ], ['productId', 'productVersionId', 'optionId'], [], [
                'option' => $belongsTo('propertyGroupOption', ['optionId'], ['id']),
            ]),
            'propertyGroupOption' => self::entity(PropertyGroupOption::class, 'property_group_option', ['id'], [
                'id' => 'id', 'groupId' => 'property_group_id',
            ], ['id', 'groupId'], [], [
                'translations' => $hasMany('propertyGroupOptionTranslation', ['id'], ['optionId'], false),
                'translationsAlt' => $hasMany('propertyGroupOptionTranslation', ['id'], ['optionId'], false),
                'group' => $belongsTo('propertyGroup', ['groupId'], ['id']),
            ]),
            'propertyGroupOptionTranslation' => self::entity(PropertyGroupOptionTranslation::class, 'property_group_option_translation', ['optionId', 'languageId'], [
                'optionId' => 'property_group_option_id', 'languageId' => 'language_id', 'name' => 'name',
            ], ['optionId', 'languageId']),
            'propertyGroup' => self::entity(PropertyGroup::class, 'property_group', ['id'], ['id' => 'id'], ['id'], [], [
                'translations' => $hasMany('propertyGroupTranslation', ['id'], ['groupId'], false),
                'translationsAlt' => $hasMany('propertyGroupTranslation', ['id'], ['groupId'], false),
            ]),
            'propertyGroupTranslation' => self::entity(PropertyGroupTranslation::class, 'property_group_translation', ['groupId', 'languageId'], [
                'groupId' => 'property_group_id', 'languageId' => 'language_id', 'name' => 'name',
            ], ['groupId', 'languageId']),
        ];
    }

    /**
     * @param class-string $class
     * @param list<string> $primaryKey
     * @param array<string, string> $columns
     * @param list<string> $hexIds
     * @param array<string, string> $typecast
     * @param array<string, array<int, mixed>> $relations
     *
     * @return array<int, mixed>
     */
    private static function entity(string $class, string $table, array $primaryKey, array $columns, array $hexIds, array $typecast = [], array $relations = []): array
    {
        return [
            S::ENTITY => $class,
            S::DATABASE => 'default',
            S::TABLE => $table,
            S::PRIMARY_KEY => $primaryKey,
            S::COLUMNS => $columns,
            S::RELATIONS => $relations,
            S::TYPECAST => $typecast + array_fill_keys($hexIds, HexIdTypecast::RULE),
            S::TYPECAST_HANDLER => [HexIdTypecast::class, Typecast::class],
            S::SCHEMA => [],
        ];
    }
}

<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Framework\ReadStore\Contract;

use Shopware\Core\Framework\ReadStore\Document\ProductDocument;
use Shopware\Core\Framework\ReadStore\Port\StoreContext;

/**
 * The documents every adapter stores for the contract suite. They are written as the builders write them (translations already resolved per
 * language with fallback and parent inheritance, prices per currency filled with the factor, inherited values copied into variants), so the
 * suite tests the query side only.
 *
 * | doc | en name / de name                  | manufacturer | EUR / USD gross     | categories | SC1 / SC2 vis | notes                                  |
 * |-----|-----------------------------------|--------------|---------------------|------------|---------------|----------------------------------------|
 * | S1  | Apple Shirt / Apfel Hemd          | A            | 10 / 12 (own USD)   | shirts     | 30 / -        | red, rating 4.5, shipping free         |
 * | S2  | banana shirt / banana shirt (fb)  | B            | 20 / 22 (factor)    | shirts     | 30 / 10       | blue, rating 3, lower case name        |
 * | S3  | Äpfelchen Shoe / Äpfelchen Schuh  | -            | 30 / 33             | shoes      | - / 30        | no manufacturer, no rating             |
 * | S4  | Date Shirt / Dattel Hemd          | B            | 15 / 16.5           | shirts     | 10 / -        | rating 5, shipping free                |
 * | P   | Cherry Sneaker / Kirsch Turnschuh | A            | 40 / 44             | shoes      | 30 / 30       | parent, displayGroup null              |
 * | V1  | Cherry Sneaker / Kirsch Turnschuh | A (inh.)     | 40 / 44 (inh.)      | shoes inh. | 30 / 30 inh.  | option red, rule R1 cheapest 35        |
 * | V2  | Cherry Sneaker Blue / Kirsch Turnschuh | C (own) | 45 / 49.5 (own)     | shoes inh. | 30 / 30 inh.  | option blue                            |
 * | V3  | Cherry Sneaker / Kirsch Turnschuh | A (inh.)     | 40 / 44 (inh.)      | shoes inh. | 30 / 30 inh.  | inactive                               |
 *
 * V1 to V3 share displayGroup `G_P`, the simple products have their own group.
 *
 * @internal
 */
final class ContractFixtures
{
    public const EN = '2fbb5fe2e29a4d70aa5854ce7ce3e20b';
    public const DE = '0de0de0de0de0de0de0de0de0de0de00';
    public const EUR = 'b7d2554b0ce847cd82f3ac9bd1c0dfca';
    public const USD = '05d05d05d05d05d05d05d05d05d05d00';
    public const USD_FACTOR = 1.1;
    public const SC1 = '5c15c15c15c15c15c15c15c15c15c100';
    public const SC2 = '5c25c25c25c25c25c25c25c25c25c200';
    public const RULE = 'ae1ae1ae1ae1ae1ae1ae1ae1ae1ae100';

    public const CAT_ROOT = 'ca7000000000000000000000000000a0';
    public const CAT_SHIRTS = 'ca7000000000000000000000000000a1';
    public const CAT_SHOES = 'ca7000000000000000000000000000a2';
    public const MAN_A = 'aa000000000000000000000000000001';
    public const MAN_B = 'bb000000000000000000000000000002';
    public const MAN_C = 'cc000000000000000000000000000003';
    public const PROP_RED = 'ed000000000000000000000000000001';
    public const PROP_BLUE = 'b1000000000000000000000000000002';

    public const S1 = '51000000000000000000000000000001';
    public const S2 = '52000000000000000000000000000002';
    public const S3 = '53000000000000000000000000000003';
    public const S4 = '54000000000000000000000000000004';
    public const P = 'a0000000000000000000000000000000';
    public const V1 = 'a1000000000000000000000000000001';
    public const V2 = 'a2000000000000000000000000000002';
    public const V3 = 'a3000000000000000000000000000003';
    public const G_P = '9a000000000000000000000000000000';

    public const ALL = [self::S1, self::S2, self::S3, self::S4, self::P, self::V1, self::V2, self::V3];

    /**
     * @param list<string> $ruleIds
     */
    public static function context(
        string $language = self::DE,
        string $salesChannel = self::SC1,
        string $currency = self::EUR,
        array $ruleIds = [],
        bool $gross = true,
    ): StoreContext {
        return new StoreContext($language, $salesChannel, $currency, $ruleIds, $gross);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function documents(): array
    {
        $parent = [
            'en' => 'Cherry Sneaker', 'de' => 'Kirsch Turnschuh', 'manufacturer' => self::MAN_A, 'eur' => 40.0, 'usd' => null,
            'categories' => [self::CAT_SHOES], 'visibilities' => [self::SC1 => 30, self::SC2 => 30], 'properties' => [],
            'rating' => 4.0, 'shippingFree' => false, 'stock' => 0, 'keywords' => ['cherry' => 500.0, 'sneaker' => 300.0],
            'deKeywords' => ['kirsch' => 500.0, 'turnschuh' => 300.0],
        ];

        return [
            self::product(self::S1, 'SW-S1', [
                'en' => 'Apple Shirt', 'de' => 'Apfel Hemd', 'manufacturer' => self::MAN_A, 'eur' => 10.0, 'usd' => 12.0,
                'categories' => [self::CAT_SHIRTS], 'visibilities' => [self::SC1 => 30], 'properties' => [self::PROP_RED],
                'rating' => 4.5, 'shippingFree' => true, 'stock' => 5, 'keywords' => ['apple' => 500.0, 'shirt' => 700.0],
                'deKeywords' => ['apfel' => 500.0, 'hemd' => 700.0], 'releaseDate' => '2026-01-10 00:00:00.000',
            ]),
            self::product(self::S2, 'SW-S2', [
                'en' => 'banana shirt', 'de' => 'banana shirt', 'manufacturer' => self::MAN_B, 'eur' => 20.0, 'usd' => null,
                'categories' => [self::CAT_SHIRTS], 'visibilities' => [self::SC1 => 30, self::SC2 => 10], 'properties' => [self::PROP_BLUE],
                'rating' => 3.0, 'shippingFree' => false, 'stock' => 7, 'keywords' => ['banana' => 500.0, 'shirt' => 350.0],
                'deKeywords' => [], 'releaseDate' => '2026-03-01 00:00:00.000',
            ]),
            self::product(self::S3, 'SW-S3', [
                'en' => 'Äpfelchen Shoe', 'de' => 'Äpfelchen Schuh', 'manufacturer' => null, 'eur' => 30.0, 'usd' => null,
                'categories' => [self::CAT_SHOES], 'visibilities' => [self::SC2 => 30], 'properties' => [],
                'rating' => null, 'shippingFree' => false, 'stock' => 1, 'keywords' => ['apfelchen' => 500.0, 'shoe' => 700.0],
                'deKeywords' => ['apfelchen' => 500.0, 'schuh' => 700.0],
            ]),
            self::product(self::S4, 'SW-S4', [
                'en' => 'Date Shirt', 'de' => 'Dattel Hemd', 'manufacturer' => self::MAN_B, 'eur' => 15.0, 'usd' => null,
                'categories' => [self::CAT_SHIRTS], 'visibilities' => [self::SC1 => 10], 'properties' => [],
                'rating' => 5.0, 'shippingFree' => true, 'stock' => 3, 'keywords' => ['date' => 500.0, 'shirt' => 700.0],
                'deKeywords' => ['dattel' => 500.0, 'hemd' => 700.0],
            ]),
            self::product(self::P, 'SW-P', [...$parent, 'displayGroup' => null, 'childCount' => 3,
                'variantListingConfig' => ['displayParent' => false, 'mainVariantId' => self::V2, 'configuratorGroupConfig' => null]]),
            self::product(self::V1, 'SW-P.1', [...$parent, 'parentId' => self::P, 'displayGroup' => self::G_P, 'properties' => [self::PROP_RED],
                'options' => [self::PROP_RED], 'stock' => 2, 'ruleCheapest' => 35.0]),
            self::product(self::V2, 'SW-P.2', [...$parent, 'parentId' => self::P, 'displayGroup' => self::G_P, 'en' => 'Cherry Sneaker Blue',
                'manufacturer' => self::MAN_C, 'eur' => 45.0, 'properties' => [self::PROP_BLUE], 'options' => [self::PROP_BLUE], 'stock' => 4]),
            self::product(self::V3, 'SW-P.3', [...$parent, 'parentId' => self::P, 'displayGroup' => self::G_P, 'active' => false, 'stock' => 9]),
        ];
    }

    /**
     * @param array<string, mixed> $p
     *
     * @return array<string, mixed>
     */
    private static function product(string $id, string $number, array $p): array
    {
        $document = new ProductDocument();
        $document->id = $id;
        $document->parentId = $p['parentId'] ?? null;
        $document->productNumber = $number;
        $document->displayGroup = \array_key_exists('displayGroup', $p) ? $p['displayGroup'] : md5($id);
        $document->variantListingConfig = $p['variantListingConfig'] ?? null;
        $document->childCount = $p['childCount'] ?? 0;
        $document->active = $p['active'] ?? true;
        $document->available = $p['stock'] > 0;
        $document->stock = $p['stock'];
        $document->availableStock = $p['stock'];
        $document->ratingAverage = $p['rating'];
        $document->shippingFree = $p['shippingFree'];
        $document->releaseDate = $p['releaseDate'] ?? null;
        $document->name = [self::EN => $p['en'], self::DE => $p['de']];
        $document->manufacturerId = $p['manufacturer'];
        $document->manufacturer = $p['manufacturer'] === null ? null : ['id' => $p['manufacturer'], 'name' => [self::EN => 'M ' . $p['manufacturer'], self::DE => 'M ' . $p['manufacturer']]];
        $document->customFields = [self::EN => ['swag_color' => $p['en'] === 'Apple Shirt' ? 'Red' : null], self::DE => ['swag_color' => $p['en'] === 'Apple Shirt' ? 'Rot' : null]];

        $usd = $p['usd'] ?? round($p['eur'] * self::USD_FACTOR, 2);
        $document->price = array_values(array_filter([
            ['currencyId' => self::EUR, 'gross' => $p['eur'], 'net' => round($p['eur'] / 1.19, 2), 'linked' => true],
            $p['usd'] !== null ? ['currencyId' => self::USD, 'gross' => $p['usd'], 'net' => round($p['usd'] / 1.19, 2), 'linked' => true] : null,
        ]));
        $document->priceGross = [self::EUR => $p['eur'], self::USD => $usd];
        $document->priceNet = [self::EUR => round($p['eur'] / 1.19, 2), self::USD => round($usd / 1.19, 2)];
        $document->cheapestPrice = ['default' => [
            self::EUR => ['gross' => $p['eur'], 'net' => round($p['eur'] / 1.19, 2)],
            self::USD => ['gross' => $usd, 'net' => round($usd / 1.19, 2)],
        ]];
        if (isset($p['ruleCheapest'])) {
            $document->cheapestPrice[self::RULE] = [
                self::EUR => ['gross' => $p['ruleCheapest'], 'net' => round($p['ruleCheapest'] / 1.19, 2)],
                self::USD => ['gross' => round($p['ruleCheapest'] * self::USD_FACTOR, 2), 'net' => round($p['ruleCheapest'] * self::USD_FACTOR / 1.19, 2)],
            ];
        }

        $document->visibilities = $p['visibilities'];
        /** @var list<string> $categories */
        $categories = $p['categories'];
        $document->categoryIds = $categories;
        $document->categoryTree = [self::CAT_ROOT, ...$categories];
        $document->propertyIds = $p['properties'];
        $document->optionIds = $p['options'] ?? [];
        $document->searchKeywords = [self::EN => $p['keywords'], self::DE => $p['deKeywords']];

        return $document->toArray();
    }
}

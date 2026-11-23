<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\Harness;

use Shopware\Core\Framework\Log\Package;

/**
 * Deterministic ids and values of the spike data set, shared by the seeder and the scenario parameters.
 * Ids are hex strings derived from md5, so the data is identical on all four engines.
 *
 * @internal
 */
#[Package('framework')]
final class Fixtures
{
    public const SIMPLE = 3000;
    public const PARENTS = 400;
    public const VARIANTS_PER_PARENT = 4;
    public const CATEGORIES = 50;
    public const MANUFACTURERS = 30;
    public const MEDIA = 200;
    public const COLORS = 5;
    public const SIZES = 4;

    public const WORDS = ['Alpha', 'Bravo', 'Charlie', 'Delta', 'Echo', 'Foxtrot', 'Golf', 'Hotel', 'India', 'Juliet', 'Kilo', 'Lima', 'Mike', 'November', 'Oscar', 'Papa', 'Quebec', 'Romeo', 'Sierra', 'Tango'];

    public const WRITE_PREFIX = 'SPK-W-';
    public const WRITE_PRODUCTS = 50;

    public static function id(string $kind, int|string ...$parts): string
    {
        return md5('orm-spike/' . $kind . '/' . implode('/', $parts));
    }

    public static function categoryId(int $n): string
    {
        return self::id('category', $n);
    }

    public static function manufacturerId(int $n): string
    {
        return self::id('manufacturer', $n);
    }

    public static function mediaId(int $n): string
    {
        return self::id('media', $n);
    }

    public static function simpleId(int $n): string
    {
        return self::id('product-simple', $n);
    }

    public static function parentId(int $n): string
    {
        return self::id('product-parent', $n);
    }

    public static function variantId(int $parent, int $n): string
    {
        return self::id('product-variant', $parent, $n);
    }

    public static function mediaPath(int $n): string
    {
        $hash = md5('path' . $n);

        return \sprintf('media/%s/%s/spike-%03d.jpg', substr($hash, 0, 2), substr($hash, 2, 2), $n);
    }

    public static function word(int $n): string
    {
        return self::WORDS[$n % \count(self::WORDS)];
    }

    /**
     * @return list<int> distinct category numbers of a product, two or three
     */
    public static function categoryNumbers(int $seed): array
    {
        $numbers = [$seed % self::CATEGORIES, ($seed * 7 + 3) % self::CATEGORIES];
        if ($seed % 3 === 0) {
            $numbers[] = ($seed * 11 + 5) % self::CATEGORIES;
        }

        return array_values(array_unique($numbers));
    }

    /**
     * Gross price of a product number, net is gross / 1.19 rounded to 2 decimals.
     */
    public static function gross(int $seed): float
    {
        return 10 + ($seed % 90) + 0.99;
    }

    public static function net(float $gross): float
    {
        return round($gross / 1.19, 2);
    }

    /**
     * The 50 products of S4 for one run, generated from the run id so that every run (and every implementation) writes the same data.
     *
     * @return list<array{id: string, productNumber: string, nameEn: string, nameDe: string, gross: float, net: float, stock: int, newStock: int, categoryIds: list<string>}>
     */
    public static function writeProducts(string $runId): array
    {
        $products = [];
        for ($i = 0; $i < self::WRITE_PRODUCTS; ++$i) {
            $gross = 5 + $i + 0.5;
            $products[] = [
                'id' => self::id('write', $runId, $i),
                'productNumber' => \sprintf('%s%s-%02d', self::WRITE_PREFIX, $runId, $i),
                'nameEn' => \sprintf('Write %s %02d', $runId, $i),
                'nameDe' => \sprintf('Schreiben %s %02d', $runId, $i),
                'gross' => $gross,
                'net' => self::net($gross),
                'stock' => 100 + $i,
                'newStock' => 200 + $i,
                'categoryIds' => [self::categoryId($i % self::CATEGORIES), self::categoryId(($i + 13) % self::CATEGORIES)],
            ];
        }

        return $products;
    }
}

<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Builder;

use Shopware\Core\Defaults;
use Shopware\Core\Framework\Log\Package;

/**
 * The price fields of the document, computed in PHP exactly like the SQL accessors compute them at query time:
 * {@see \Shopware\Core\Framework\DataAbstractionLayer\Dbal\FieldAccessorBuilder\PriceFieldAccessorBuilder} for `priceGross`/`priceNet` and
 * {@see \Shopware\Core\Content\Product\DataAbstractionLayer\CheapestPrice\CheapestPriceAccessorBuilder} for `cheapestPrice`.
 *
 * The SQL multiplies the JSON number (a double) with the currency factor, casts the double to `DECIMAL(30, 20)` and rounds it
 * half away from zero. Here the double is turned into its shortest decimal representation and rounded with bcmath; on orm_spike that gives
 * the same value as MySQL for every price and currency (docs/40-read-store-port-and-document.md, Q2).
 * The factor is formatted like the accessors format it (`%F` for the price, the PHP string conversion for the cheapest price).
 *
 * Cash rounding (decimals 2, interval other than 0.01) depends on the tax state of the query context in the DAL. The document rounds gross values
 * always and net values when the currency has `roundForNet`, which is what the DAL does for a context with the tax state of the value.
 *
 * @internal
 */
#[Package('framework')]
final readonly class PriceCalculator
{
    private const CHEAPEST_PATHS = [
        ['gross'],
        ['net'],
        ['listPrice', 'gross'],
        ['listPrice', 'net'],
        ['percentage', 'gross'],
        ['percentage', 'net'],
    ];

    public function __construct(private BuildScope $scope)
    {
    }

    /**
     * `IFNULL(own, parent)` of the price accessor per currency and tax state.
     *
     * @param array<string, mixed>|null $own decoded `product.price` of the product, `{c<currencyId>: {gross, net, ...}}`
     * @param array<string, mixed>|null $parent the same of its parent, `null` for a product without parent
     *
     * @return array{gross: array<string, float>, net: array<string, float>}
     */
    public function currencyPrices(?array $own, ?array $parent): array
    {
        $result = ['gross' => [], 'net' => []];
        foreach ($this->scope->currencies as $currencyId => $currency) {
            foreach (['gross', 'net'] as $state) {
                $value = $this->price($own, $currencyId, $currency, $state) ?? $this->price($parent, $currencyId, $currency, $state);
                if ($value !== null) {
                    $result[$state][$currencyId] = (float) $value;
                }
            }
        }

        return $result;
    }

    /**
     * The `cheapest_price_accessor` (of the product, the parent's when the product has none) per rule key and currency.
     *
     * @param array<string, mixed>|null $accessor decoded `{rule<ruleId|default>: {currency<currencyId>: {gross, net, listPrice?, percentage?}}}`
     *
     * @return array<string, array<string, array<string, mixed>>> rule id or `default` => currency id => `{gross, net, listPrice?: {gross, net}, percentage?: {gross, net}}`
     */
    public function cheapestPrice(?array $accessor): array
    {
        $result = [];
        foreach ($accessor ?? [] as $ruleKey => $prices) {
            if (!\is_array($prices) || !str_starts_with((string) $ruleKey, 'rule')) {
                continue;
            }
            $own = [];
            foreach ($this->scope->currencies as $currencyId => $currency) {
                /** @var array<string, mixed> $entry */
                $entry = [];
                foreach (self::CHEAPEST_PATHS as $path) {
                    $value = self::at($prices, ['currency' . $currencyId, ...$path]);
                    $factor = 1.0;
                    if ($value === null && $currencyId !== Defaults::CURRENCY) {
                        $value = self::at($prices, ['currency' . Defaults::CURRENCY, ...$path]);
                        $factor = (float) (string) $currency['factor'];
                    }
                    if ($value === null) {
                        continue;
                    }

                    $rounded = $this->round((float) $value * $factor, $currency, end($path) === 'gross');
                    if ($path[0] === 'percentage') {
                        // the accessor returns the price-to-list-price ratio
                        $rounded = bcsub('100', $rounded, 20);
                    }

                    if (\count($path) === 1) {
                        $entry[$path[0]] = (float) $rounded;
                    } else {
                        $sub = \is_array($entry[$path[0]] ?? null) ? $entry[$path[0]] : [];
                        $sub[$path[1]] = (float) $rounded;
                        $entry[$path[0]] = $sub;
                    }
                }
                if ($entry !== []) {
                    $own[$currencyId] = $entry;
                }
            }
            $result[substr((string) $ruleKey, 4)] = $own;
        }

        return $result;
    }

    /**
     * `ROUND(CAST(COALESCE(own currency, default currency * factor) AS DECIMAL(30, 20)), decimals)` plus cash rounding.
     *
     * @param array<string, mixed>|null $price
     * @param array{factor: float, decimals: int, interval: float, roundForNet: bool} $currency
     */
    private function price(?array $price, string $currencyId, array $currency, string $state): ?string
    {
        if ($price === null) {
            return null;
        }

        $value = self::at($price, ['c' . $currencyId, $state]);
        $factor = 1.0;
        if ($value === null && $currencyId !== Defaults::CURRENCY) {
            $value = self::at($price, ['c' . Defaults::CURRENCY, $state]);
            $factor = (float) \sprintf('%F', $currency['factor']);
        }

        if ($value === null) {
            return null;
        }

        return $this->round((float) $value * $factor, $currency, $state === 'gross');
    }

    /**
     * @param array{factor: float, decimals: int, interval: float, roundForNet: bool} $currency
     *
     * @return numeric-string
     */
    private function round(float $value, array $currency, bool $gross): string
    {
        $rounded = bcround(self::decimal($value), $currency['decimals']);
        \assert(is_numeric($rounded));

        if ($currency['decimals'] === 2 && ($gross || $currency['roundForNet'])) {
            // `100 / (interval * 100)`, written into the SQL as PHP string, the division has scale 4 on MySQL
            $multiplier = self::decimal((float) (string) (100 / ($currency['interval'] * 100)));
            $scaled = bcround(bcmul($rounded, $multiplier, 20), 0);
            \assert(is_numeric($scaled));
            $rounded = bcdiv($scaled, $multiplier, 4);
        }

        return $rounded;
    }

    /**
     * The shortest decimal representation of a double, what `CAST(<double> AS DECIMAL(30, 20))` keeps.
     *
     * @return numeric-string
     */
    private static function decimal(float $value): string
    {
        $string = json_encode($value, \JSON_THROW_ON_ERROR);
        if (str_contains($string, 'e') || str_contains($string, 'E')) {
            $string = \sprintf('%.20F', $value);
        }

        \assert(is_numeric($string));

        return $string;
    }

    /**
     * @param array<mixed> $data
     * @param list<string> $path
     */
    private static function at(array $data, array $path): float|int|string|null
    {
        foreach ($path as $key) {
            if (!\is_array($data) || !\array_key_exists($key, $data)) {
                return null;
            }
            $data = $data[$key];
        }

        return \is_float($data) || \is_int($data) || (\is_string($data) && is_numeric($data)) ? $data : null;
    }
}

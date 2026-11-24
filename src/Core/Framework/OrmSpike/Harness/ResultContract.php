<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\Harness;

use Shopware\Core\Framework\Log\Package;

/**
 * The normalized result arrays of S1 to S5. All implementations return exactly these shapes, `--verify` compares them
 * with the DAL result after {@see self::canonical()} (keys sorted, no floats).
 *
 * Rules:
 * - ids are lower case 32 character hex strings, names are the translated value in the language chain [de-DE, en-GB] with parent inheritance
 *   (`COALESCE(own de, parent de, own en, parent en)`),
 * - prices are strings with 2 decimals (`'12.99'`), gross, default currency, from the `price` field of the product or, if it has none, of its parent,
 * - `null` for a value that does not exist (no manufacturer, no cover),
 * - `items` keeps the query order, `categories`, `options`, `prices` are sorted as written below.
 *
 * @internal
 */
#[Package('framework')]
final class ResultContract
{
    /**
     * `['total' => int, 'items' => list<['id' => hex, 'productNumber' => string, 'name' => string, 'manufacturer' => ?string (translated name), 'cover' => ?string (media.path), 'price' => ?string]>]`
     * Filter: `categoriesRo` contains the category, active (inherited), storefront visibility >= 10 (inherited), sorted by translated name ASC, then productNumber ASC, page 2 of 24.
     */
    public const S1 = 'total, items[id, productNumber, name, manufacturer, cover, price]';

    /**
     * `['id', 'productNumber', 'name', 'description' => ?string, 'manufacturerId' => ?hex, 'manufacturer' => ?string, 'cover' => ?string, 'price' => ?string,
     *   'prices' => list<['quantityStart' => int, 'quantityEnd' => ?int, 'price' => string]> sorted by quantityStart,
     *   'options' => list<['group' => string, 'name' => string]> sorted by group, name,
     *   'categories' => list<hex> sorted]`
     * `prices` and `categories` are the rows of the variant or, if it has none, of its parent (inheritance of to-many associations).
     */
    public const S2 = 'id, productNumber, name, description, manufacturerId, manufacturer, cover, price, prices[], options[], categories[]';

    /**
     * `['total' => int, 'items' => list<['id', 'productNumber', 'name', 'manufacturer', 'price']>, 'aggregations' => ['minPrice' => ?string, 'maxPrice' => ?string, 'manufacturers' => array<hex, int> sorted by key]]`
     * Filter: translated name contains the term (case-insensitive), manufacturer (inherited) in the list, active and visible as in S1; sorted by name ASC, productNumber ASC, page 1 of 24.
     * Aggregations run over all matches, not over the page.
     */
    public const S3 = 'total, items[id, productNumber, name, manufacturer, price], aggregations[minPrice, maxPrice, manufacturers]';

    /**
     * `['total' => int, 'items' => list<['id', 'productNumber', 'manufacturer' => ?string]>]`
     */
    public const S5 = 'total, items[id, productNumber, manufacturer]';

    /**
     * `['total' => int, 'items' => list<['displayGroup' => ?string]>, 'aggregations' => as S3]`
     * Term {@see Params::$searchWord} through the storefront search (`ProductSearchBuilder`, keyword table), `ProductAvailableFilter` (search visibility), the
     * variant grouping of `ProductListingLoader` (`FieldGrouping('displayGroup')` and `NotEqualsFilter('displayGroup', null)`), manufacturer list as post
     * filter, sorted by name ASC, productNumber ASC (explicit, score ranking is not part of the contract), page 1 of 24.
     * `total` is the number of groups after the post filter. The DAL picks the representative row of a group arbitrarily (`any_value()`, the group is ordered by
     * `MIN(name)` for ASC), so id, name, price and manufacturer of the hit are NOT compared; per hit only its group key `displayGroup` is. Aggregations run
     * without grouping and without post filters (min and max of `price.gross`, terms of `manufacturerId`).
     */
    public const R3B = 'total, items[id, productNumber, name, manufacturer, price], aggregations[minPrice, maxPrice, manufacturers]';

    /**
     * R4: `['items' => list<S2 shape>]` in the order of {@see Params::$mgetIds}.
     */
    public const R4 = 'items[S2 shape]';

    public static function price(float|int|string|null $value): ?string
    {
        return $value === null ? null : number_format((float) $value, 2, '.', '');
    }

    public static function hex(string $id): string
    {
        return strtolower(str_replace('-', '', $id));
    }

    /**
     * Recursively sorts string keys so two results can be compared with `===` / JSON.
     */
    public static function canonical(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }

        $value = array_map(self::canonical(...), $value);
        if (!array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}

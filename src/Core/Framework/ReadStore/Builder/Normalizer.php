<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Builder;

use Shopware\Core\Defaults;
use Shopware\Core\Framework\Log\Package;

/**
 * Brings the raw JSON values both builders read (from the column or from the entity) into one layout, so the documents of
 * {@see SqlDocumentBuilder} and {@see DalDocumentBuilder} are equal.
 *
 * @internal
 */
#[Package('framework')]
final class Normalizer
{
    /**
     * A price list (`product.price`, `product_price.price`) as `[{currencyId, gross, net, linked, listPrice?, regulationPrice?, percentage?}]`,
     * sorted by currency id. Nested prices keep `{gross, net, linked}`, `percentage` keeps `{gross, net}`.
     *
     * @param array<mixed>|null $prices the decoded column (`{c<currencyId>: {...}}`) or a list of such entries
     *
     * @return list<array<string, mixed>>|null
     */
    public static function priceList(?array $prices): ?array
    {
        if ($prices === null) {
            return null;
        }

        $list = [];
        foreach ($prices as $price) {
            if (!\is_array($price)) {
                continue;
            }

            $entry = [
                'currencyId' => strtolower((string) ($price['currencyId'] ?? '')),
                'gross' => (float) ($price['gross'] ?? 0),
                'net' => (float) ($price['net'] ?? 0),
                'linked' => (bool) ($price['linked'] ?? false),
            ];
            foreach (['listPrice', 'regulationPrice'] as $key) {
                if (isset($price[$key]) && \is_array($price[$key])) {
                    $entry[$key] = [
                        'gross' => (float) ($price[$key]['gross'] ?? 0),
                        'net' => (float) ($price[$key]['net'] ?? 0),
                        'linked' => (bool) ($price[$key]['linked'] ?? false),
                    ];
                }
            }
            if (isset($price['percentage']) && \is_array($price['percentage'])) {
                $entry['percentage'] = ['gross' => (float) ($price['percentage']['gross'] ?? 0), 'net' => (float) ($price['percentage']['net'] ?? 0)];
            }
            $list[] = $entry;
        }

        usort($list, static fn (array $a, array $b): int => $a['currencyId'] <=> $b['currencyId']);

        return $list;
    }

    /**
     * `{displayParent, mainVariantId, configuratorGroupConfig}` of the parent, `null` when not set.
     *
     * @param array<mixed>|null $config
     *
     * @return array{displayParent: ?bool, mainVariantId: ?string, configuratorGroupConfig: ?array<mixed>}|null
     */
    public static function listingConfig(?array $config): ?array
    {
        if ($config === null) {
            return null;
        }

        $mainVariantId = $config['mainVariantId'] ?? null;
        $groupConfig = $config['configuratorGroupConfig'] ?? null;

        return [
            'displayParent' => isset($config['displayParent']) ? (bool) $config['displayParent'] : null,
            'mainVariantId' => \is_string($mainVariantId) ? strtolower($mainVariantId) : null,
            'configuratorGroupConfig' => \is_array($groupConfig) ? $groupConfig : null,
        ];
    }

    /**
     * A stored date in `Defaults::STORAGE_DATE_TIME_FORMAT` (UTC, three fraction digits).
     * PostgreSQL returns `timestamp(3)` as text without trailing zeros (`.75` for `.750`), MySQL always with three digits.
     */
    public static function date(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (new \DateTimeImmutable((string) $value, new \DateTimeZone('UTC')))->format(Defaults::STORAGE_DATE_TIME_FORMAT);
    }
}

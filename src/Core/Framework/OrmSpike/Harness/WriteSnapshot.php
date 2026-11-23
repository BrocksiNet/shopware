<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\Harness;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Adapter\Database\Dialect\IdBinding;
use Shopware\Core\Framework\Log\Package;

/**
 * Reads the rows an S4 run wrote into one normalized array, to compare the write of an implementation with the DAL write.
 *
 * @internal
 */
#[Package('framework')]
final class WriteSnapshot
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array<string, array<string, mixed>> by product number
     */
    public function read(WriteSet $set): array
    {
        $ids = IdBinding::values($set->ids());
        $in = ['ids' => $ids];
        $type = ['ids' => IdBinding::listType()];

        $products = $this->connection->fetchAllAssociative(
            'SELECT ' . IdBinding::text('id') . ' AS id, product_number, stock, active, price, ' . IdBinding::text('tax_id') . ' AS tax_id, ' . IdBinding::text('version_id') . ' AS version_id FROM product WHERE id IN (:ids)',
            $in,
            $type
        );

        $result = [];
        $byId = [];
        foreach ($products as $row) {
            $price = json_decode((string) $row['price'], true, 512, \JSON_THROW_ON_ERROR);
            $currency = 'c' . Defaults::CURRENCY;
            $entry = $price[$currency] ?? $price[0] ?? [];
            $byId[strtolower((string) $row['id'])] = (string) $row['product_number'];
            $result[(string) $row['product_number']] = [
                'stock' => (int) $row['stock'],
                'active' => (bool) $row['active'],
                'tax' => strtolower((string) $row['tax_id']),
                'version' => strtolower((string) $row['version_id']),
                'priceGross' => number_format((float) ($entry['gross'] ?? 0), 2, '.', ''),
                'priceNet' => number_format((float) ($entry['net'] ?? 0), 2, '.', ''),
                'translations' => [],
                'categories' => [],
                'visibilities' => [],
            ];
        }

        foreach ($this->connection->fetchAllAssociative(
            'SELECT ' . IdBinding::text('product_id') . ' AS pid, ' . IdBinding::text('language_id') . ' AS lid, name FROM product_translation WHERE product_id IN (:ids)',
            $in,
            $type
        ) as $row) {
            $result[$byId[strtolower((string) $row['pid'])]]['translations'][strtolower((string) $row['lid'])] = $row['name'];
        }
        foreach ($this->connection->fetchAllAssociative(
            'SELECT ' . IdBinding::text('product_id') . ' AS pid, ' . IdBinding::text('category_id') . ' AS cid FROM product_category WHERE product_id IN (:ids)',
            $in,
            $type
        ) as $row) {
            $result[$byId[strtolower((string) $row['pid'])]]['categories'][] = strtolower((string) $row['cid']);
        }
        foreach ($this->connection->fetchAllAssociative(
            'SELECT ' . IdBinding::text('product_id') . ' AS pid, ' . IdBinding::text('sales_channel_id') . ' AS sid, visibility FROM product_visibility WHERE product_id IN (:ids)',
            $in,
            $type
        ) as $row) {
            $result[$byId[strtolower((string) $row['pid'])]]['visibilities'][strtolower((string) $row['sid'])] = (int) $row['visibility'];
        }

        ksort($result);
        foreach ($result as &$entry) {
            ksort($entry['translations']);
            sort($entry['categories']);
            ksort($entry['visibilities']);
        }

        return $result;
    }
}

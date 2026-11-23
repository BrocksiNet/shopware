<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\Harness;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Log\Package;

/**
 * Removes the products of S4 (product number prefix `SPK-W-`) and their rows, outside of the timed part.
 *
 * @internal
 */
#[Package('framework')]
final class WriteCleaner
{
    private const CHILDREN = ['product_translation', 'product_category', 'product_category_tree', 'product_visibility', 'product_price', 'product_media', 'product_search_keyword'];

    public function __construct(private readonly Connection $connection)
    {
    }

    public function clean(): void
    {
        $like = Fixtures::WRITE_PREFIX . '%';
        foreach (self::CHILDREN as $table) {
            $this->connection->executeStatement(
                'DELETE FROM ' . $table . ' WHERE product_id IN (SELECT id FROM product WHERE product_number LIKE :p)',
                ['p' => $like]
            );
        }
        $this->connection->executeStatement('DELETE FROM product WHERE product_number LIKE :p', ['p' => $like]);
    }
}

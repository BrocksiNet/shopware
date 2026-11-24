<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Builder;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Adapter\Database\Dialect\IdBinding;
use Shopware\Core\Framework\Log\Package;

/**
 * Walks the product ids of the live version in batches (keyset on the primary key), the input of a rebuild.
 *
 * @internal
 */
#[Package('framework')]
final readonly class ProductIds
{
    public function __construct(private Connection $connection)
    {
    }

    public function count(): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM product WHERE version_id = :version',
            ['version' => IdBinding::value(Defaults::LIVE_VERSION)],
            ['version' => IdBinding::type()]
        );
    }

    /**
     * @return \Generator<int, list<string>>
     */
    public function batches(int $size): \Generator
    {
        $last = null;
        while (true) {
            $sql = \sprintf('SELECT %s FROM product WHERE version_id = :version', IdBinding::text('id'));
            $params = ['version' => IdBinding::value(Defaults::LIVE_VERSION)];
            $types = ['version' => IdBinding::type()];
            if ($last !== null) {
                $sql .= ' AND id > :last';
                $params['last'] = IdBinding::value($last);
                $types['last'] = IdBinding::type();
            }

            /** @var list<string> $ids */
            $ids = $this->connection->fetchFirstColumn($sql . ' ORDER BY id LIMIT ' . $size, $params, $types);
            if ($ids === []) {
                return;
            }

            yield $ids;
            $last = $ids[\count($ids) - 1];
        }
    }
}

<?php declare(strict_types=1);

namespace Shopware\Core\Checkout\Customer\Service;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Adapter\Database\Dialect\IdBinding;
use Shopware\Core\Framework\Log\Package;

#[Package('checkout')]
/**
 * @final
 */
class ProductReviewCountService
{
    /**
     * @internal
     */
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param list<string> $reviewIds
     */
    public function updateReviewCount(array $reviewIds): void
    {
        /** @var list<string> $affectedCustomers */
        $affectedCustomers = array_filter($this->connection->fetchFirstColumn(
            'SELECT DISTINCT ' . IdBinding::text('`customer_id`') . ' FROM product_review WHERE id IN (:ids) AND `customer_id` IS NOT NULL',
            ['ids' => IdBinding::values($reviewIds)],
            ['ids' => IdBinding::listType()]
        ));

        foreach ($affectedCustomers as $customerId) {
            $this->updateReviewCountForCustomer($customerId);
        }
    }

    public function updateReviewCountForCustomer(string $customerId): void
    {
        $this->connection->executeStatement(
            'UPDATE `customer` SET review_count = (
                  SELECT COUNT(*) FROM `product_review` WHERE `customer_id` = :id AND `status` = 1
            ) WHERE id = :id',
            ['id' => IdBinding::value(IdBinding::toHex($customerId))],
            ['id' => IdBinding::type()]
        );
    }
}

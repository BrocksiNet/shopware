<?php
declare(strict_types=1);

namespace Shopware\Storefront\Theme\ConfigLoader;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Adapter\Database\Dialect\IdBinding;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;

#[Package('discovery')]
class DatabaseAvailableThemeProvider extends AbstractAvailableThemeProvider
{
    /**
     * @internal
     */
    public function __construct(private readonly Connection $connection)
    {
    }

    public function getDecorated(): AbstractAvailableThemeProvider
    {
        throw new DecorationPatternException(self::class);
    }

    public function load(Context $context, bool $activeOnly): array
    {
        $qb = $this->connection->createQueryBuilder()
            ->from('theme_sales_channel')
            ->select(IdBinding::text('sales_channel_id'), IdBinding::text('theme_id'))
            ->leftJoin('theme_sales_channel', 'sales_channel', 'sales_channel', 'sales_channel.id = theme_sales_channel.sales_channel_id')
            ->where('sales_channel.type_id = :typeId')
            ->setParameter('typeId', IdBinding::value(Defaults::SALES_CHANNEL_TYPE_STOREFRONT));

        if ($activeOnly) {
            $qb->andWhere('sales_channel.active = 1');
        }

        /** @var array<string, string> $keyValue */
        $keyValue = $qb->executeQuery()->fetchAllKeyValue();

        return $keyValue;
    }
}

<?php declare(strict_types=1);

namespace Shopware\Tests\Integration\Core\Content\Seo;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Seo\SeoUrlPersister;
use Shopware\Core\Framework\Adapter\Database\Dialect\IdBinding;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Shopware\Core\Test\TestDefaults;

/**
 * @internal
 */
#[Package('inventory')]
#[CoversClass(SeoUrlPersister::class)]
class SeoUrlPersisterTest extends TestCase
{
    use IntegrationTestBehaviour;

    private const ROUTE = 'frontend.detail.page';

    /**
     * 16 ASCII characters: as raw bytes this id is valid UTF-8 and a content based id codec would not recognise it as an id.
     */
    private const ASCII_BYTES_ID = '0123456789abcdef';

    public function testWritesAndRetargetsUrlsForIdsThatAreValidUtf8(): void
    {
        $foreignKey = bin2hex(self::ASCII_BYTES_ID);
        static::assertTrue(mb_check_encoding(hex2bin($foreignKey) ?: '', 'UTF-8'));

        $persister = static::getContainer()->get(SeoUrlPersister::class);
        $salesChannel = $this->getSalesChannel();
        $context = Context::createDefaultContext();

        $persister->updateSeoUrls($context, self::ROUTE, [$foreignKey], [$this->seoUrl($foreignKey, 'detail/first', 'first-path')], $salesChannel);

        $rows = $this->fetchRows($foreignKey);
        static::assertCount(1, $rows);
        static::assertSame('detail/first', $rows[0]['path_info']);
        static::assertSame('first-path', $rows[0]['seo_path_info']);
        static::assertSame(TestDefaults::SALES_CHANNEL, $rows[0]['sales_channel_id']);
        static::assertSame(Uuid::fromBytesToHex(hex2bin($foreignKey) ?: ''), $rows[0]['foreign_key']);

        // second run: the canonical url is obsoleted, retargeted and a new row is inserted
        $persister->updateSeoUrls($context, self::ROUTE, [$foreignKey], [$this->seoUrl($foreignKey, 'detail/first', 'second-path')], $salesChannel);

        $rows = $this->fetchRows($foreignKey);
        static::assertCount(2, $rows);
        $canonical = array_values(array_filter($rows, static fn (array $row): bool => (int) $row['is_canonical'] === 1));
        static::assertCount(1, $canonical);
        static::assertSame('second-path', $canonical[0]['seo_path_info']);

        // an entity that disappears from the generated urls is marked as deleted
        $persister->updateSeoUrls($context, self::ROUTE, [$foreignKey], [], $salesChannel);
        foreach ($this->fetchRows($foreignKey) as $row) {
            static::assertSame(1, (int) $row['is_deleted']);
        }
    }

    public function testTakesOverAnExistingPathForRandomIds(): void
    {
        $persister = static::getContainer()->get(SeoUrlPersister::class);
        $salesChannel = $this->getSalesChannel();
        $context = Context::createDefaultContext();

        $first = Uuid::randomHex();
        $second = Uuid::randomHex();

        $persister->updateSeoUrls($context, self::ROUTE, [$first], [$this->seoUrl($first, 'detail/a', 'shared-path')], $salesChannel);
        $persister->updateSeoUrls($context, self::ROUTE, [$second], [$this->seoUrl($second, 'detail/b', 'shared-path')], $salesChannel);

        $rows = $this->fetchRows($second);
        static::assertCount(1, $rows);
        static::assertSame('detail/b', $rows[0]['path_info']);
    }

    /**
     * @return array<string, mixed>
     */
    private function seoUrl(string $foreignKey, string $pathInfo, string $seoPathInfo): array
    {
        return [
            'foreignKey' => $foreignKey,
            'salesChannelId' => TestDefaults::SALES_CHANNEL,
            'routeName' => self::ROUTE,
            'pathInfo' => $pathInfo,
            'seoPathInfo' => $seoPathInfo,
            'isCanonical' => true,
            'isModified' => false,
            'isDeleted' => false,
        ];
    }

    private function getSalesChannel(): SalesChannelEntity
    {
        /** @var EntityRepository<SalesChannelCollection> $repository */
        $repository = static::getContainer()->get('sales_channel.repository');
        $salesChannel = $repository->search(new \Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria([TestDefaults::SALES_CHANNEL]), Context::createDefaultContext())->first();
        static::assertInstanceOf(SalesChannelEntity::class, $salesChannel);

        return $salesChannel;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchRows(string $foreignKey): array
    {
        $connection = static::getContainer()->get(Connection::class);

        /** @var list<array<string, mixed>> $rows */
        $rows = $connection->fetchAllAssociative(
            'SELECT ' . IdBinding::selectText('sales_channel_id') . ' AS sales_channel_id, ' . IdBinding::selectText('foreign_key') . ' AS foreign_key,
                path_info, seo_path_info, is_canonical, is_deleted
             FROM seo_url WHERE foreign_key = :fk AND route_name = :route ORDER BY created_at, seo_path_info',
            ['fk' => IdBinding::value($foreignKey), 'route' => self::ROUTE],
            ['fk' => IdBinding::type()]
        );

        foreach ($rows as &$row) {
            $row['sales_channel_id'] = IdBinding::toHex((string) $row['sales_channel_id']);
            $row['foreign_key'] = IdBinding::toHex((string) $row['foreign_key']);
        }

        return $rows;
    }
}

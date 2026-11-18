<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\System\SalesChannel\Context;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Statement;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\CartPersister;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\QueryBuilder;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Util\Random;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextPersister;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Shopware\Core\Test\TestDefaults;
use Symfony\Component\Clock\NativeClock;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * @internal
 */
#[Package('framework')]
#[CoversClass(SalesChannelContextPersister::class)]
class SalesChannelContextPersisterTest extends TestCase
{
    private SalesChannelContextPersister $contextPersister;

    private Stub&Result $statement;

    protected function setUp(): void
    {
        $this->statement = static::createStub(Result::class);

        $connection = static::createStub(Connection::class);
        $queryBuilder = static::createStub(QueryBuilder::class);
        $queryBuilder->method('executeQuery')->willReturn($this->statement);
        $connection->method('createQueryBuilder')->willReturn($queryBuilder);

        $this->contextPersister = new SalesChannelContextPersister(
            $connection,
            static::createStub(EventDispatcherInterface::class),
            static::createStub(CartPersister::class),
            new NativeClock(),
            'P1D', // 1 day expiration is the default value
        );
    }

    public function testSaveRetriesTheReplaceFallbackWhenAConcurrentRequestWinsTheRace(): void
    {
        $uniqueViolation = static fn (): UniqueConstraintViolationException => new UniqueConstraintViolationException(
            new class('duplicate key') extends \Exception implements DriverException {
                public function getSQLState(): ?string
                {
                    return '23505';
                }
            },
            null
        );

        $result = static::createStub(Result::class);
        $result->method('fetchAllAssociative')->willReturn([]);
        $queryBuilder = static::createStub(QueryBuilder::class);
        $queryBuilder->method('executeQuery')->willReturn($result);

        // The upsert conflicts on the second unique key, which sends the persister into the delete and insert fallback.
        $upsert = $this->createMock(Statement::class);
        $upsert->method('executeStatement')->willThrowException($uniqueViolation());

        $inserts = 0;
        $deletes = 0;
        $connection = $this->createMock(Connection::class);
        $connection->method('createQueryBuilder')->willReturn($queryBuilder);
        $connection->method('prepare')->willReturn($upsert);
        $connection->method('transactional')->willReturnCallback(static fn (\Closure $closure) => $closure($connection));
        $connection->method('executeStatement')->willReturnCallback(
            static function (string $sql) use (&$inserts, &$deletes, $uniqueViolation): int {
                if (str_starts_with($sql, 'DELETE')) {
                    ++$deletes;

                    return 0;
                }

                // The first two inserts lose against a concurrent request, the third one succeeds.
                if (++$inserts < 3) {
                    throw $uniqueViolation();
                }

                return 1;
            }
        );

        $persister = new SalesChannelContextPersister(
            $connection,
            static::createStub(EventDispatcherInterface::class),
            static::createStub(CartPersister::class),
            new NativeClock(),
        );

        $persister->save(Random::getAlphanumericString(32), ['foo' => 'bar'], TestDefaults::SALES_CHANNEL, Uuid::randomHex());

        static::assertSame(3, $inserts);
        static::assertSame(3, $deletes);
    }

    public function testLoadWithNoContextFoundReturnsEmptyArray(): void
    {
        // Simulate no context found in the database
        $this->statement->method('fetchAllAssociative')->willReturn([]);

        $result = $this->contextPersister->load(Random::getAlphanumericString(32), TestDefaults::SALES_CHANNEL, Uuid::randomHex());
        static::assertSame([], $result);
    }

    public function testLoadUsesCustomerBoundContext(): void
    {
        $token = Random::getAlphanumericString(32);
        $customerToken = Random::getAlphanumericString(32);
        $customerId = Uuid::randomHex();
        $updatedAt = new \DateTimeImmutable();

        $this->statement->method('fetchAllAssociative')->willReturn([
            [
                'updated_at' => $updatedAt->format(Defaults::STORAGE_DATE_TIME_FORMAT),
                'payload' => json_encode(['type' => 'guest'], \JSON_THROW_ON_ERROR),
                'token' => $token,
                'sales_channel_id' => Uuid::fromHexToBytes(TestDefaults::SALES_CHANNEL),
                'customer_id' => null,
            ],
            [
                'updated_at' => $updatedAt->format(Defaults::STORAGE_DATE_TIME_FORMAT),
                'payload' => json_encode(['type' => 'customer'], \JSON_THROW_ON_ERROR),
                'token' => $customerToken,
                'sales_channel_id' => Uuid::fromHexToBytes(TestDefaults::SALES_CHANNEL),
                'customer_id' => Uuid::fromHexToBytes($customerId),
            ],
        ]);

        static::assertSame(
            [
                'type' => 'customer',
                'expired' => false,
                'token' => $customerToken,
                SalesChannelContextService::CUSTOMER_ID => $customerId,
            ],
            $this->contextPersister->load($token, TestDefaults::SALES_CHANNEL, $customerId)
        );
    }

    /**
     * @param array<string, string> $payload
     * @param array<string, string|bool> $expected
     */
    #[DataProvider('tokenExpirationDataProvider')]
    public function testLoadContextAgainstTokenExpiration(string $token, ?string $customerId, \DateTimeImmutable $updatedAt, array $payload, array $expected): void
    {
        $this->statement->method('fetchAllAssociative')->willReturn([
            [
                'updated_at' => $updatedAt->format(Defaults::STORAGE_DATE_TIME_FORMAT),
                'payload' => \json_encode($payload, \JSON_THROW_ON_ERROR),
                'token' => $token,
            ],
        ]);

        $result = $this->contextPersister->load($token, TestDefaults::SALES_CHANNEL, $customerId);

        static::assertSame($expected, $result);
    }

    public static function tokenExpirationDataProvider(): \Generator
    {
        $token = Random::getAlphanumericString(32);
        $customerId = Uuid::randomHex();
        $updatedAt = new \DateTimeImmutable();
        // When we expire the token, we set it to 2 days ago, as there is 1 day expiration

        yield 'it keeps payload when customerId is provided and token is expired' => [
            'token' => $token,
            'customerId' => $customerId,
            'updatedAt' => $updatedAt->sub(new \DateInterval('P2D')),
            'payload' => ['a_key' => 'aValue'],
            'expected' => ['a_key' => 'aValue', 'expired' => true, 'token' => $token],
        ];
        yield 'it withdraws payload when customerId is not provided and token is expired' => [
            'token' => $token,
            'customerId' => null,
            'updatedAt' => $updatedAt->sub(new \DateInterval('P2D')),
            'payload' => ['a_key' => 'aValue', 'anotherKey' => 'anotherValue'],
            'expected' => ['expired' => true, 'token' => $token],
        ];

        yield 'it keeps payload when customerId is not provided and token is not expired' => [
            'token' => $token,
            'customerId' => null,
            'updatedAt' => $updatedAt,
            'payload' => ['a_key' => 'aValue'],
            'expected' => ['a_key' => 'aValue', 'expired' => false, 'token' => $token],
        ];
        yield 'it keeps payload when customerId is provided and token is not expired' => [
            'token' => $token,
            'customerId' => $customerId,
            'updatedAt' => $updatedAt,
            'payload' => ['a_key' => 'aValue'],
            'expected' => ['a_key' => 'aValue', 'expired' => false, 'token' => $token],
        ];
    }
}

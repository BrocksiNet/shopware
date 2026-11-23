<?php declare(strict_types=1);

namespace Shopware\Tests\Integration\Core\Content\Newsletter\Domain;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Newsletter\Domain\Dal\DalNewsletterRecipientStorage;
use Shopware\Core\Content\Newsletter\Domain\NewsletterRecipientStorage;
use Shopware\Core\Content\Newsletter\Domain\NewsletterRecipientWrite;
use Shopware\Core\Content\Newsletter\Domain\NewsletterStatus;
use Shopware\Core\Content\Newsletter\Domain\Sql\SqlNewsletterRecipientStorage;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityLoadedEvent;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Test\Integration\EventDispatcher\EventHookDispatcher;
use Shopware\Core\Test\TestDefaults;
use Symfony\Component\Clock\NativeClock;

/**
 * Both adapters must behave the same, on every database the project supports.
 *
 * @internal
 */
#[Package('after-sales')]
#[CoversClass(DalNewsletterRecipientStorage::class)]
#[CoversClass(SqlNewsletterRecipientStorage::class)]
class NewsletterRecipientStorageTest extends TestCase
{
    use IntegrationTestBehaviour;

    /**
     * @return iterable<string, array{class-string<NewsletterRecipientStorage>}>
     */
    public static function adapters(): iterable
    {
        yield 'dal' => [DalNewsletterRecipientStorage::class];
        yield 'sql' => [SqlNewsletterRecipientStorage::class];
    }

    /**
     * @param class-string<NewsletterRecipientStorage> $adapter
     */
    #[DataProvider('adapters')]
    public function testInsertAndRead(string $adapter): void
    {
        $storage = $this->storage($adapter);
        $write = $this->write('insert@example.com', salutationId: $this->salutationId(), customFields: ['a' => 1]);

        $storage->upsert($write, Context::createDefaultContext());

        $found = $storage->findByEmail('insert@example.com', TestDefaults::SALES_CHANNEL, Context::createDefaultContext());
        static::assertNotNull($found);
        static::assertSame($write->id, $found->id);
        static::assertSame(NewsletterStatus::NotSet, $found->status);
        static::assertSame($write->hash, $found->hash);
        static::assertSame('Dto', $found->firstName);
        static::assertNull($found->lastName);
        static::assertNull($found->confirmedAt);
        static::assertSame(['a' => 1], $found->customFields);
        static::assertSame('mr', $found->salutation?->salutationKey);
        static::assertNotEmpty($found->salutation->displayName);
        static::assertSame($write->languageId, $found->languageId);
        static::assertSame(TestDefaults::SALES_CHANNEL, $found->salesChannelId);
        static::assertNotNull($found->createdAt);
    }

    /**
     * @param class-string<NewsletterRecipientStorage> $adapter
     */
    #[DataProvider('adapters')]
    public function testUpsertKeepsFieldsThatAreNotProvided(string $adapter): void
    {
        $storage = $this->storage($adapter);
        $first = $this->write('keep@example.com');
        $storage->upsert($first, Context::createDefaultContext());

        $second = new NewsletterRecipientWrite(
            id: $first->id,
            email: 'keep@example.com',
            status: NewsletterStatus::Direct,
            hash: Uuid::randomHex(),
            languageId: Defaults::LANGUAGE_SYSTEM,
            salesChannelId: TestDefaults::SALES_CHANNEL,
            lastName: 'Again',
        );
        $storage->upsert($second, Context::createDefaultContext());

        $found = $storage->findByEmail('keep@example.com', TestDefaults::SALES_CHANNEL, Context::createDefaultContext());
        static::assertNotNull($found);
        static::assertSame('Dto', $found->firstName);
        static::assertSame('Again', $found->lastName);
        static::assertSame(NewsletterStatus::Direct, $found->status);
        static::assertSame($second->hash, $found->hash);
        static::assertSame(1, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM `newsletter_recipient` WHERE `email` = :e', ['e' => 'keep@example.com']));
    }

    /**
     * @param class-string<NewsletterRecipientStorage> $adapter
     */
    #[DataProvider('adapters')]
    public function testConfirmAndOptOutByHash(string $adapter): void
    {
        $storage = $this->storage($adapter);
        $write = $this->write('flow@example.com');
        $storage->upsert($write, Context::createDefaultContext());

        $byHash = $storage->findByHash($write->hash, Context::createDefaultContext());
        static::assertNotNull($byHash);
        static::assertSame($write->id, $byHash->id);

        $at = new \DateTimeImmutable('2026-01-02 03:04:05', new \DateTimeZone('UTC'));
        $storage->confirm($write->id, $at, Context::createDefaultContext());

        $confirmed = $storage->findByHash($write->hash, Context::createDefaultContext());
        static::assertNotNull($confirmed);
        static::assertSame(NewsletterStatus::OptIn, $confirmed->status);
        static::assertSame('2026-01-02 03:04:05', $confirmed->confirmedAt?->format('Y-m-d H:i:s'));

        $storage->optOut($write->id, Context::createDefaultContext());

        $optedOut = $storage->findByEmail('flow@example.com', TestDefaults::SALES_CHANNEL, Context::createDefaultContext());
        static::assertNotNull($optedOut);
        static::assertSame(NewsletterStatus::OptOut, $optedOut->status);
        static::assertNotNull($optedOut->confirmedAt);
    }

    public function testSalutationFollowsTheLanguageChain(): void
    {
        $parentLanguageId = $this->connection()->fetchOne(
            'SELECT LOWER(HEX(`language_id`)) FROM `salutation_translation` WHERE `language_id` <> :system LIMIT 1',
            ['system' => Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM)]
        );
        if (!\is_string($parentLanguageId)) {
            static::markTestSkipped('The salutation has no translation besides the system language.');
        }

        $write = $this->write('chain@example.com', salutationId: $this->salutationId());
        $this->storage(DalNewsletterRecipientStorage::class)->upsert($write, Context::createDefaultContext());

        // a sub language without own translation that inherits from a parent which is not the system language
        $context = new Context(new SystemSource(), [], Defaults::CURRENCY, [Uuid::randomHex(), $parentLanguageId, Defaults::LANGUAGE_SYSTEM]);

        $viaDal = $this->storage(DalNewsletterRecipientStorage::class)->findByEmail('chain@example.com', TestDefaults::SALES_CHANNEL, $context);
        $viaSql = $this->storage(SqlNewsletterRecipientStorage::class)->findByEmail('chain@example.com', TestDefaults::SALES_CHANNEL, $context);

        static::assertNotNull($viaSql);
        static::assertEquals($viaDal?->salutation, $viaSql->salutation);
    }

    /**
     * @param class-string<NewsletterRecipientStorage> $adapter
     */
    #[DataProvider('adapters')]
    public function testUnknownLookupsReturnNull(string $adapter): void
    {
        $storage = $this->storage($adapter);

        static::assertNull($storage->findByEmail('nobody@example.com', TestDefaults::SALES_CHANNEL, Context::createDefaultContext()));
        static::assertNull($storage->findByHash('does-not-exist', Context::createDefaultContext()));
        static::assertNull($storage->findCustomerEmail(Uuid::randomHex(), Context::createDefaultContext()));
    }

    /**
     * @param class-string<NewsletterRecipientStorage> $adapter
     */
    #[DataProvider('adapters')]
    public function testExplicitNullClearsAProvidedField(string $adapter): void
    {
        $storage = $this->storage($adapter);
        $first = $this->write('clear@example.com');
        $storage->upsert($first, Context::createDefaultContext());

        $second = new NewsletterRecipientWrite(
            id: $first->id,
            email: 'clear@example.com',
            status: NewsletterStatus::NotSet,
            hash: Uuid::randomHex(),
            languageId: Defaults::LANGUAGE_SYSTEM,
            salesChannelId: TestDefaults::SALES_CHANNEL,
            firstName: null,
            lastName: 'Kept',
            provided: ['firstName', 'lastName'],
        );
        $storage->upsert($second, Context::createDefaultContext());

        $found = $storage->findByEmail('clear@example.com', TestDefaults::SALES_CHANNEL, Context::createDefaultContext());
        static::assertNotNull($found);
        static::assertNull($found->firstName);
        static::assertSame('Kept', $found->lastName);
    }

    public function testDalEventEntityKeepsExtensionsAddedOnLoad(): void
    {
        EventHookDispatcher::fromContainer(static::getContainer())->on(
            'newsletter_recipient.loaded',
            static function (EntityLoadedEvent $event): void {
                foreach ($event->getEntities() as $entity) {
                    $entity->addExtension('probe', new ArrayStruct(['loaded' => true]));
                }
            }
        );

        $storage = $this->storage(DalNewsletterRecipientStorage::class);
        $storage->upsert($this->write('extension@example.com'), Context::createDefaultContext());
        $found = $storage->findByEmail('extension@example.com', TestDefaults::SALES_CHANNEL, Context::createDefaultContext());
        static::assertNotNull($found);

        $entity = $storage->toEventEntity($found);

        static::assertTrue($entity->hasExtension('probe'));
        static::assertSame($found->id, $entity->getId());
    }

    public function testAdaptersReturnEqualReadModels(): void
    {
        $write = $this->write('same@example.com', salutationId: $this->salutationId(), customFields: ['b' => 'x']);
        $this->storage(DalNewsletterRecipientStorage::class)->upsert($write, Context::createDefaultContext());

        $viaDal = $this->storage(DalNewsletterRecipientStorage::class)->findByEmail('same@example.com', TestDefaults::SALES_CHANNEL, Context::createDefaultContext());
        $viaSql = $this->storage(SqlNewsletterRecipientStorage::class)->findByEmail('same@example.com', TestDefaults::SALES_CHANNEL, Context::createDefaultContext());

        static::assertNotNull($viaDal);
        static::assertEquals($viaDal, $viaSql);
    }

    /**
     * @param class-string<NewsletterRecipientStorage> $adapter
     */
    private function storage(string $adapter): NewsletterRecipientStorage
    {
        $container = static::getContainer();

        if ($adapter === SqlNewsletterRecipientStorage::class) {
            return new SqlNewsletterRecipientStorage($this->connection(), new NativeClock());
        }

        /** @var EntityRepository<*> $recipients */
        $recipients = $container->get('newsletter_recipient.repository');
        /** @var EntityRepository<*> $customers */
        $customers = $container->get('customer.repository');

        return new DalNewsletterRecipientStorage($recipients, $customers);
    }

    private function connection(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }

    private function salutationId(): string
    {
        $id = $this->connection()->fetchOne('SELECT LOWER(HEX(`id`)) FROM `salutation` WHERE `salutation_key` = \'mr\'');
        static::assertIsString($id);

        return $id;
    }

    /**
     * @param array<string, mixed>|null $customFields
     */
    private function write(string $email, ?string $salutationId = null, ?array $customFields = null): NewsletterRecipientWrite
    {
        return new NewsletterRecipientWrite(
            id: Uuid::randomHex(),
            email: $email,
            status: NewsletterStatus::NotSet,
            hash: Uuid::randomHex(),
            languageId: Defaults::LANGUAGE_SYSTEM,
            salesChannelId: TestDefaults::SALES_CHANNEL,
            firstName: 'Dto',
            salutationId: $salutationId,
            customFields: $customFields,
        );
    }
}

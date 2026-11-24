<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Framework\ReadStore\Projector;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Product\Events\ProductIndexerEvent;
use Shopware\Core\Content\Product\Events\ProductStockAlteredEvent;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityWriteResult;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityDeletedEvent;
use Shopware\Core\Framework\ReadStore\Adapter\ArrayStore\ArrayStorage;
use Shopware\Core\Framework\ReadStore\Builder\DocumentBuilder;
use Shopware\Core\Framework\ReadStore\Projector\ProjectProductsHandler;
use Shopware\Core\Framework\ReadStore\Projector\ProjectProductsMessage;
use Shopware\Core\Framework\ReadStore\Projector\ReadStoreProjector;
use Shopware\Core\Framework\ReadStore\Projector\ReadStoreProjectorSubscriber;
use Shopware\Core\Framework\ReadStore\Projector\StorageRegistry;
use Shopware\Tests\Unit\Core\Framework\ReadStore\Contract\ContractFixtures as F;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * @internal
 */
#[CoversClass(ReadStoreProjector::class)]
#[CoversClass(ReadStoreProjectorSubscriber::class)]
#[CoversClass(ProjectProductsMessage::class)]
#[CoversClass(ProjectProductsHandler::class)]
class ReadStoreProjectorTest extends TestCase
{
    public function testSyncModeProjectsAndRemovesDocuments(): void
    {
        $storage = new ArrayStorage();
        $storage->setup('g1');
        $storage->swap('g1');

        $fixtures = [];
        foreach (F::documents() as $document) {
            $fixtures[(string) $document['id']] = $document;
        }
        $builder = new class($fixtures) implements DocumentBuilder {
            /**
             * @param array<string, array<string, mixed>> $fixtures
             */
            public function __construct(public array $fixtures)
            {
            }

            public static function key(): string
            {
                return 'sql';
            }

            public function reset(): void
            {
            }

            public function build(array $ids): array
            {
                $out = [];
                foreach ($ids as $id) {
                    if (isset($this->fixtures[$id])) {
                        $out[$id] = $this->fixtures[$id];
                    }
                }

                return $out;
            }
        };

        $registry = new StorageRegistry(
            new ServiceLocator(['array' => static fn () => $storage]),
            new ServiceLocator(['sql' => static fn () => $builder]),
        );
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');

        $projector = new ReadStoreProjector($registry, $bus, 'array', 'sql', ReadStoreProjector::MODE_SYNC);
        $subscriber = new ReadStoreProjectorSubscriber($projector);

        $subscriber->onIndexed(new ProductIndexerEvent([F::S1, F::S2], Context::createDefaultContext()));
        static::assertNotNull($storage->get(F::S1));
        static::assertNotNull($storage->get(F::S2));

        // stock altered re-projects the product
        $builder->fixtures[F::S1]['stock'] = 99;
        $subscriber->onStockAltered(new ProductStockAlteredEvent([F::S1], Context::createDefaultContext()));
        static::assertSame(99, $storage->get(F::S1)['stock'] ?? null);

        // delete removes the document
        $subscriber->onDeleted(new EntityDeletedEvent(ProductDefinition::ENTITY_NAME, [new EntityWriteResult(F::S2, [], ProductDefinition::ENTITY_NAME, EntityWriteResult::OPERATION_DELETE)], Context::createDefaultContext()));
        static::assertNull($storage->get(F::S2));
    }

    public function testQueueModeDispatchesDeduplicatableMessageAndHandlerProjects(): void
    {
        $storage = new ArrayStorage();
        $storage->setup('g1');
        $storage->swap('g1');
        $doc = F::documents()[0];

        $builder = new class($doc) implements DocumentBuilder {
            /**
             * @param array<string, mixed> $doc
             */
            public function __construct(private readonly array $doc)
            {
            }

            public static function key(): string
            {
                return 'sql';
            }

            public function reset(): void
            {
            }

            public function build(array $ids): array
            {
                return \in_array(F::S1, $ids, true) ? [F::S1 => $this->doc] : [];
            }
        };

        $registry = new StorageRegistry(
            new ServiceLocator(['array' => static fn () => $storage]),
            new ServiceLocator(['sql' => static fn () => $builder]),
        );

        $dispatched = [];
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static function (object $message) use (&$dispatched): Envelope {
            $dispatched[] = $message;

            return new Envelope($message);
        });

        $projector = new ReadStoreProjector($registry, $bus, 'array', 'sql', ReadStoreProjector::MODE_QUEUE);
        $projector->schedule([F::S2, F::S1]);
        $projector->schedule([F::S1, F::S2]);

        static::assertCount(2, $dispatched);
        static::assertInstanceOf(ProjectProductsMessage::class, $dispatched[0]);
        static::assertInstanceOf(ProjectProductsMessage::class, $dispatched[1]);
        static::assertSame($dispatched[0]->deduplicationId(), $dispatched[1]->deduplicationId());
        static::assertNull($storage->get(F::S1));

        (new ProjectProductsHandler($projector))($dispatched[0]);
        static::assertNotNull($storage->get(F::S1));
    }
}

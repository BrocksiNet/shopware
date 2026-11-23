<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Framework\OrmSpike\DalV2\Write;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Logging\Middleware as LoggingMiddleware;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Shopware\Core\Framework\Adapter\Database\ConnectionFactory;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\ExceptionHandlerRegistry;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\RowLoader;
use Shopware\Core\Framework\DataAbstractionLayer\DefinitionInstanceRegistry;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\UpdateCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\EntityExistence;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Validation\WriteCommandExceptionEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Write\WriteContext;
use Shopware\Core\Framework\OrmSpike\DalV2\Write\WriteGatewayV2;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Batched updates must behave like the same updates run one by one: same result, same exception, same failed command.
 *
 * Runs on in-memory SQLite. Set DAL_V2_GATEWAY_TEST_URL to a DATABASE_URL to run the same cases on MySQL, MariaDB or PostgreSQL
 * (the table dal_v2_gateway_probe is created and dropped there).
 *
 * @internal
 */
#[CoversClass(WriteGatewayV2::class)]
class WriteGatewayV2BatchSemanticsTest extends TestCase
{
    private const TABLE = 'dal_v2_gateway_probe';

    private Connection $connection;

    private EntityDefinition $definition;

    private DefinitionInstanceRegistry $registry;

    /**
     * @var list<string>
     */
    private array $updates = [];

    protected function setUp(): void
    {
        $logger = new class(function (string $sql): void {
            $this->updates[] = $sql;
        }) extends AbstractLogger {
            /**
             * @param \Closure(string): void $onUpdate
             */
            public function __construct(private readonly \Closure $onUpdate)
            {
            }

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $sql = $context['sql'] ?? null;
                if (\is_string($sql) && preg_match('/^\s*UPDATE\b/i', $sql) === 1) {
                    ($this->onUpdate)($sql);
                }
            }
        };

        $this->connection = $this->connect(new LoggingMiddleware($logger));
        $this->connection->executeStatement('DROP TABLE IF EXISTS ' . self::TABLE);
        $this->connection->executeStatement('CREATE TABLE ' . self::TABLE . ' (
            id VARCHAR(10) NOT NULL PRIMARY KEY,
            code INT NOT NULL,
            stock INT NOT NULL,
            CONSTRAINT uniq_dal_v2_gateway_probe_code UNIQUE (code),
            CONSTRAINT check_dal_v2_gateway_probe_stock CHECK (stock < 100)
        )');
        $this->connection->executeStatement('INSERT INTO ' . self::TABLE . ' (id, code, stock) VALUES (\'a\', 1, 0), (\'b\', 2, 0), (\'c\', 3, 0)');

        $this->definition = new class extends EntityDefinition {
            public function getEntityName(): string
            {
                return 'dal_v2_gateway_probe';
            }

            protected function defineFields(): FieldCollection
            {
                return new FieldCollection([
                    (new StringField('id', 'id'))->addFlags(new PrimaryKey()),
                    new IntField('code', 'code'),
                    new IntField('stock', 'stock'),
                ]);
            }
        };
        $this->registry = new DefinitionInstanceRegistry(new ContainerBuilder(), [], []);
        $this->registry->register($this->definition);
        $this->updates = [];
    }

    protected function tearDown(): void
    {
        $this->connection->executeStatement('DROP TABLE IF EXISTS ' . self::TABLE);
        $this->connection->close();
    }

    public function testNonUniqueColumnsAreStillBatched(): void
    {
        $this->gateway(true)->execute($this->updates(['a' => ['stock' => 5], 'b' => ['stock' => 6], 'c' => ['stock' => 7]]), $this->writeContext());

        static::assertCount(1, $this->updates);
        static::assertSame(['a' => 5, 'b' => 6, 'c' => 7], $this->column('stock'));
    }

    public function testUniqueSwapThatSucceedsOneByOneSucceedsWithBatching(): void
    {
        foreach ([false, true] as $batch) {
            $this->reset();
            // with two rows the batched statement changes a first on every engine, which collides with b before b moved
            $this->connection->executeStatement('DELETE FROM ' . self::TABLE . ' WHERE id = \'c\'');
            $this->gateway($batch)->execute($this->updates(['b' => ['code' => 3], 'a' => ['code' => 2]]), $this->writeContext());

            static::assertSame(['a' => 2, 'b' => 3], $this->column('code'), $batch ? 'batched' : 'one by one');
        }
    }

    public function testUniqueSwapThatFailsOneByOneFailsWithBatching(): void
    {
        foreach ([false, true] as $batch) {
            $this->reset();
            $commands = $this->updates(['a' => ['code' => 2], 'b' => ['code' => 4]]);

            try {
                $this->gateway($batch)->execute($commands, $this->writeContext());
                static::fail('expected a unique constraint violation ' . ($batch ? 'batched' : 'one by one'));
            } catch (UniqueConstraintViolationException) {
            }

            static::assertSame([true, false], $this->failedFlags($commands));
            static::assertSame(['a' => 1, 'b' => 2, 'c' => 3], $this->column('code'));
        }
    }

    public function testFailedBatchMarksTheFailingCommandForTheExceptionEvent(): void
    {
        foreach ([false, true] as $batch) {
            $this->reset();
            // the third command has other columns, so it ends the batch of the first two and triggers its flush
            $commands = $this->updates(['a' => ['stock' => 5], 'b' => ['stock' => 500]]);
            $commands[] = $this->updates(['c' => ['stock' => 7, 'code' => 9]])[0];

            $flagsInEvent = null;
            $dispatcher = new EventDispatcher();
            $dispatcher->addListener(WriteCommandExceptionEvent::class, function (WriteCommandExceptionEvent $event) use (&$flagsInEvent): void {
                $flagsInEvent = $this->failedFlags(array_values($event->getCommands()));
            });

            try {
                $this->gateway($batch, $dispatcher)->execute($commands, $this->writeContext());
                static::fail('expected a check constraint violation ' . ($batch ? 'batched' : 'one by one'));
            } catch (DriverException) {
            }

            $label = $batch ? 'batched' : 'one by one';
            static::assertSame([false, true, false], $this->failedFlags($commands), $label);
            static::assertSame([false, true, false], $flagsInEvent, $label);
            static::assertSame(['a' => 0, 'b' => 0, 'c' => 0], $this->column('stock'), $label);
        }
    }

    private function connect(LoggingMiddleware $logging): Connection
    {
        $url = getenv('DAL_V2_GATEWAY_TEST_URL');
        if (!\is_string($url) || $url === '') {
            return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], (new Configuration())->setMiddlewares([$logging]));
        }

        $previous = $_SERVER['DATABASE_URL'] ?? null;
        $_SERVER['DATABASE_URL'] = $url;
        try {
            return ConnectionFactory::create([$logging]);
        } finally {
            if ($previous === null) {
                unset($_SERVER['DATABASE_URL']);
            } else {
                $_SERVER['DATABASE_URL'] = $previous;
            }
        }
    }

    private function reset(): void
    {
        $this->connection->executeStatement('DELETE FROM ' . self::TABLE);
        $this->connection->executeStatement('INSERT INTO ' . self::TABLE . ' (id, code, stock) VALUES (\'a\', 1, 0), (\'b\', 2, 0), (\'c\', 3, 0)');
        $this->updates = [];
    }

    private function gateway(bool $batch, ?EventDispatcher $dispatcher = null): WriteGatewayV2
    {
        return new WriteGatewayV2(
            100,
            $this->connection,
            $dispatcher ?? new EventDispatcher(),
            new ExceptionHandlerRegistry([]),
            $this->registry,
            new RowLoader($this->connection),
            null,
            false,
            $batch,
        );
    }

    private function writeContext(): WriteContext
    {
        return WriteContext::createFromContext(Context::createDefaultContext());
    }

    /**
     * @param array<string, array<string, int>> $payloads
     *
     * @return list<UpdateCommand>
     */
    private function updates(array $payloads): array
    {
        $commands = [];
        foreach ($payloads as $id => $payload) {
            $commands[] = new UpdateCommand(
                $this->definition,
                $payload,
                ['id' => $id],
                new EntityExistence(self::TABLE, ['id' => $id], true, false, false, []),
                '',
            );
        }

        return $commands;
    }

    /**
     * @param list<mixed> $commands
     *
     * @return list<bool>
     */
    private function failedFlags(array $commands): array
    {
        return array_map(static fn (UpdateCommand $command): bool => $command->isFailed(), $commands);
    }

    /**
     * @return array<string, int>
     */
    private function column(string $column): array
    {
        $values = [];
        foreach ($this->connection->fetchAllAssociative('SELECT id, ' . $column . ' FROM ' . self::TABLE . ' ORDER BY id') as $row) {
            $values[(string) $row['id']] = (int) $row[$column];
        }

        return $values;
    }
}

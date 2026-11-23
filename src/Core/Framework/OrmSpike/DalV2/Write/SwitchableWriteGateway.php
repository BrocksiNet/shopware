<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\DalV2\Write;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Adapter\Database\Dialect\SqlDialect;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\ExceptionHandlerRegistry;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\RowLoader;
use Shopware\Core\Framework\DataAbstractionLayer\DefinitionInstanceRegistry;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\WriteCommandQueue;
use Shopware\Core\Framework\DataAbstractionLayer\Write\EntityExistence;
use Shopware\Core\Framework\DataAbstractionLayer\Write\EntityWriteGatewayInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Write\WriteContext;
use Shopware\Core\Framework\DataAbstractionLayer\Write\WriteParameterBag;
use Shopware\Core\Framework\Log\Package;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Decorates the DAL write gateway. Without a mode it forwards to the trunk gateway (the `dal` baseline is unchanged),
 * with a mode set by the dalv2 implementation it forwards to {@see WriteGatewayV2}.
 *
 * The decorator is needed because the field serializers (translations, to-many associations) hold their own
 * `WriteCommandExtractor` that is wired to the container's gateway, so a second write stack would not reach them.
 *
 * @internal
 */
#[Package('framework')]
class SwitchableWriteGateway implements EntityWriteGatewayInterface
{
    /**
     * @var array{key: bool, upd: bool}|null
     */
    public static ?array $mode = null;

    /**
     * @var array<string, WriteGatewayV2>
     */
    private array $variants = [];

    public function __construct(
        private readonly EntityWriteGatewayInterface $inner,
        private readonly int $batchSize,
        private readonly Connection $connection,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly ExceptionHandlerRegistry $exceptionHandlerRegistry,
        private readonly DefinitionInstanceRegistry $registry,
        private readonly RowLoader $rowLoader,
        private readonly SqlDialect $dialect,
    ) {
    }

    public function prefetchExistences(WriteParameterBag $parameterBag): void
    {
        $this->target()->prefetchExistences($parameterBag);
    }

    public function getExistence(EntityDefinition $definition, array $primaryKey, array $data, WriteCommandQueue $commandQueue): EntityExistence
    {
        return $this->target()->getExistence($definition, $primaryKey, $data, $commandQueue);
    }

    public function execute(array $commands, WriteContext $context): void
    {
        $this->target()->execute($commands, $context);
    }

    private function target(): EntityWriteGatewayInterface
    {
        if (self::$mode === null) {
            return $this->inner;
        }

        $id = (self::$mode['key'] ? 'k' : '') . (self::$mode['upd'] ? 'u' : '');

        return $this->variants[$id] ??= new WriteGatewayV2(
            $this->batchSize,
            $this->connection,
            $this->dispatcher,
            $this->exceptionHandlerRegistry,
            $this->registry,
            $this->rowLoader,
            $this->dialect,
            self::$mode['key'],
            self::$mode['upd'],
        );
    }
}

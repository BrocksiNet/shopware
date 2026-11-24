<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Framework\ReadStore\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\ReadStore\Integration\ReadStoreConfig;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @internal
 */
#[CoversClass(ReadStoreConfig::class)]
final class ReadStoreConfigTest extends TestCase
{
    public function testHeaderAndSalesChannelConfigPrecedence(): void
    {
        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('get')
            ->with('core.readStore.enabled', 'sc-1')
            ->willReturn(true);
        $systemConfig->method('getString')
            ->willReturnMap([
                ['core.readStore.engine', 'sc-1', 'sql-mysql'],
                ['core.readStore.shape', 'sc-1', ReadStoreConfig::SHAPE_ENTITY],
            ]);

        $stack = new RequestStack();
        $config = new ReadStoreConfig($systemConfig, $stack);

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn('sc-1');

        static::assertTrue($config->isEnabled($context));
        static::assertSame('sql-mysql', $config->storeKey($context));
        static::assertSame(ReadStoreConfig::SHAPE_ENTITY, $config->shape($context));

        // Request header overrides per-sales-channel system config
        $request = new Request();
        $request->headers->set('sw-read-store', 'opensearch-valkey');
        $request->headers->set('sw-read-store-shape', ReadStoreConfig::SHAPE_DTO);
        $stack->push($request);

        static::assertTrue($config->isEnabled($context));
        static::assertSame('opensearch-valkey', $config->storeKey($context));
        static::assertSame(ReadStoreConfig::SHAPE_DTO, $config->shape($context));

        // Explicit "dal" header disables the read store for this request
        $request->headers->set('sw-read-store', 'dal');
        static::assertFalse($config->isEnabled($context));

        // Runtime override wins over everything
        $config->override(true, 'array', ReadStoreConfig::SHAPE_ENTITY, false, false);
        static::assertTrue($config->isEnabled($context));
        static::assertSame('array', $config->storeKey($context));
        static::assertFalse($config->livePrices());
        static::assertFalse($config->liveStock());

        $config->reset();
        static::assertFalse($config->isEnabled($context));
        static::assertTrue($config->livePrices());
    }
}

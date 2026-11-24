<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Integration;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Per sales channel switch for the read store route integration.
 *
 * Resolution order: runtime override (tests/CLI) -> HTTP request header (`sw-read-store`, `sw-read-store-shape`)
 * -> per-sales-channel `system_config` (`core.readStore.*`) -> environment variables (`READ_STORE_*`).
 *
 * @internal
 */
#[Package('framework')]
final class ReadStoreConfig
{
    public const SHAPE_ENTITY = 'entity';
    public const SHAPE_DTO = 'dto';

    private ?bool $overrideEnabled = null;

    private ?string $overrideStoreKey = null;

    private ?string $overrideShape = null;

    private bool $livePrices = true;

    private bool $liveStock = true;

    public function __construct(
        private readonly SystemConfigService $systemConfig,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function override(?bool $enabled, ?string $storeKey = null, ?string $shape = null, bool $livePrices = true, bool $liveStock = true): void
    {
        $this->overrideEnabled = $enabled;
        $this->overrideStoreKey = $storeKey;
        $this->overrideShape = $shape;
        $this->livePrices = $livePrices;
        $this->liveStock = $liveStock;
    }

    public function reset(): void
    {
        $this->overrideEnabled = null;
        $this->overrideStoreKey = null;
        $this->overrideShape = null;
        $this->livePrices = true;
        $this->liveStock = true;
    }

    public function isEnabled(SalesChannelContext $context): bool
    {
        if ($this->overrideEnabled !== null) {
            return $this->overrideEnabled;
        }

        $header = $this->requestStack->getCurrentRequest()?->headers->get('sw-read-store');
        if ($header !== null && $header !== '') {
            return !\in_array(strtolower($header), ['0', 'false', 'off', 'dal'], true);
        }

        $config = $this->systemConfig->get('core.readStore.enabled', $context->getSalesChannelId());
        if ($config !== null) {
            return (bool) $config;
        }

        $env = $_SERVER['READ_STORE_ENABLED'] ?? $_ENV['READ_STORE_ENABLED'] ?? getenv('READ_STORE_ENABLED');

        return \in_array(strtolower((string) $env), ['1', 'true', 'on', 'yes'], true);
    }

    public function storeKey(SalesChannelContext $context): string
    {
        if ($this->overrideStoreKey !== null) {
            return $this->overrideStoreKey;
        }

        $header = $this->requestStack->getCurrentRequest()?->headers->get('sw-read-store');
        if ($header !== null && $header !== '' && !\in_array(strtolower($header), ['1', 'true', 'on', '0', 'false', 'off', 'dal'], true)) {
            return $header;
        }

        $config = $this->systemConfig->getString('core.readStore.engine', $context->getSalesChannelId());
        if ($config !== '') {
            return $config;
        }

        $env = $_SERVER['READ_STORE_ENGINE'] ?? $_ENV['READ_STORE_ENGINE'] ?? getenv('READ_STORE_ENGINE');

        return \is_string($env) && $env !== '' ? $env : 'array';
    }

    public function shape(SalesChannelContext $context): string
    {
        if ($this->overrideShape !== null) {
            return $this->overrideShape;
        }

        $header = $this->requestStack->getCurrentRequest()?->headers->get('sw-read-store-shape');
        if ($header === self::SHAPE_DTO || $header === self::SHAPE_ENTITY) {
            return $header;
        }

        $config = $this->systemConfig->getString('core.readStore.shape', $context->getSalesChannelId());
        if ($config === self::SHAPE_DTO || $config === self::SHAPE_ENTITY) {
            return $config;
        }

        $env = $_SERVER['READ_STORE_SHAPE'] ?? $_ENV['READ_STORE_SHAPE'] ?? getenv('READ_STORE_SHAPE');

        return $env === self::SHAPE_DTO ? self::SHAPE_DTO : self::SHAPE_ENTITY;
    }

    public function livePrices(): bool
    {
        return $this->livePrices;
    }

    public function liveStock(): bool
    {
        return $this->liveStock;
    }
}

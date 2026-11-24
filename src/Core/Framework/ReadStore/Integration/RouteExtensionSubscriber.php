<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Integration;

use Shopware\Core\Content\Product\Extension\ProductDetailRouteExtension;
use Shopware\Core\Content\Product\Extension\ResolveListingExtension;
use Shopware\Core\Framework\Log\Package;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Hooks the Store API product routes through {@see \Shopware\Core\Framework\Extensions\ExtensionDispatcher} `.pre`
 * events (ADR `2026-09-24-replace-abstract-route-classes-with-extension-events.md`).
 *
 * - `product-detail-route.load.pre` serves `/store-api/product/{productId}`
 * - `listing-loader.resolve.pre` serves both `/store-api/product-listing/{categoryId}` and `/store-api/search`
 *   (because `ProductSearchRoute::_load()` runs `ProductSearchBuilder::build()` and delegates to `ProductListingLoader::load()`)
 *
 * When the gateway falls back (unsupported criteria, store disabled, store down), it returns null and the core
 * DAL implementation runs untouched.
 *
 * @internal
 */
#[Package('framework')]
final readonly class RouteExtensionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private ReadStoreGateway $gateway,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ProductDetailRouteExtension::NAME . '.pre' => ['onProductDetailPre', 100],
            ResolveListingExtension::NAME . '.pre' => ['onResolveListingPre', 100],
        ];
    }

    public function onProductDetailPre(ProductDetailRouteExtension $extension): void
    {
        $response = $this->gateway->detail(
            $extension->productId,
            $extension->request,
            $extension->context,
            $extension->criteria,
        );

        if ($response !== null) {
            $extension->result = $response;
            $extension->stopPropagation();
        }
    }

    public function onResolveListingPre(ResolveListingExtension $extension): void
    {
        $result = $this->gateway->listing($extension->criteria, $extension->context);

        if ($result !== null) {
            $extension->result = $result;
            $extension->stopPropagation();
        }
    }
}

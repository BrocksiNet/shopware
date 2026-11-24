<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Port\Schema;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\ReadStore\Document\ProductDocument;
use Shopware\Core\Framework\ReadStore\Port\NotSupportedByEngine;
use Shopware\Core\Framework\ReadStore\Port\StoreContext;

/**
 * Maps a logical field of the store criteria to the document paths for a context. Every adapter uses it, so the field semantics live in one place.
 *
 * - plain fields (`productNumber`, `categoryTree`, ...) map to themselves;
 * - {@see FieldScope::LANGUAGE}: `name` -> `name.<languageId>`, {@see FieldScope::SALES_CHANNEL}: `visibilities` -> `visibilities.<salesChannelId>`,
 *   {@see FieldScope::CURRENCY}: `priceGross` -> `priceGross.<currencyId>`;
 * - `price`, `price.gross`, `price.net` -> `priceGross`/`priceNet` of the context currency (`price` by the tax state), rounded at build time like
 *   `cheapestPrice`, so both need a context that rounds like its currency;
 * - `cheapestPrice[.listPrice|.percentage][.gross|.net]` -> one path per context rule id, then `default` (`COALESCE`, like the DAL accessor);
 * - `customFields.<name>` -> `customFields.<languageId>.<name>`, `extensions.<name>...` -> itself, both {@see FieldType::ANY}.
 *
 * Everything else, notably nested association fields such as `manufacturer.name`, is not queryable; the criteria bridge maps the
 * association fields the product routes use to the flat fields (`manufacturer.id` -> `manufacturerId`, `properties.id` -> `propertyIds`).
 *
 * @internal
 */
#[Package('framework')]
final class FieldResolver
{
    /**
     * @param class-string $documentClass
     */
    public function __construct(private readonly string $documentClass = ProductDocument::class)
    {
    }

    public function resolve(string $field, StoreContext $context): ResolvedField
    {
        $fields = DocumentSchema::fields($this->documentClass);
        $parts = explode('.', $field);
        $head = $parts[0];

        if ($head === 'price') {
            return $this->price($field, $parts, $context);
        }

        $definition = $fields[$head] ?? null;
        if ($definition === null) {
            throw NotSupportedByEngine::field($field, 'not a document field');
        }

        if (!$definition->queryable) {
            throw NotSupportedByEngine::field($field, 'payload only');
        }

        if ($definition->scope === FieldScope::RULE_CURRENCY) {
            return $this->cheapestPrice($field, \array_slice($parts, 1), $context);
        }

        if ($head === 'customFields' && \count($parts) > 1) {
            return new ResolvedField($field, FieldType::ANY, [['customFields', $context->languageId, ...\array_slice($parts, 1)]]);
        }

        if ($head === 'extensions' && \count($parts) > 1) {
            return new ResolvedField($field, FieldType::ANY, [$parts]);
        }

        if (\count($parts) > 1 || $definition->type === FieldType::OBJECT || $definition->type === FieldType::OBJECT_LIST) {
            throw NotSupportedByEngine::field($field, 'nested fields are not queryable');
        }

        $key = match ($definition->scope) {
            FieldScope::NONE => null,
            FieldScope::LANGUAGE => $context->languageId,
            FieldScope::CURRENCY => $context->currencyId,
            FieldScope::SALES_CHANNEL => $context->salesChannelId,
        };

        return new ResolvedField($field, $definition->type, [$key === null ? [$head] : [$head, $key]]);
    }

    /**
     * @param list<string> $parts
     */
    private function price(string $field, array $parts, StoreContext $context): ResolvedField
    {
        if (!$context->roundsLikeCurrency()) {
            throw NotSupportedByEngine::field($field, 'the context rounds prices differently than the currency the document was built with');
        }

        $state = $parts[1] ?? ($context->gross ? 'gross' : 'net');
        if (\count($parts) > 2 || !\in_array($state, ['gross', 'net'], true)) {
            throw NotSupportedByEngine::field($field, 'only price, price.gross and price.net are queryable');
        }

        return new ResolvedField($field, FieldType::FLOAT, [[$state === 'gross' ? 'priceGross' : 'priceNet', $context->currencyId]]);
    }

    /**
     * Mirrors the accessor parsing of {@see \Shopware\Core\Content\Product\DataAbstractionLayer\CheapestPrice\CheapestPriceAccessorBuilder}.
     *
     * @param list<string> $parts
     */
    private function cheapestPrice(string $field, array $parts, StoreContext $context): ResolvedField
    {
        if (!$context->roundsLikeCurrency()) {
            throw NotSupportedByEngine::field($field, 'the context rounds prices differently than the currency the document was built with');
        }

        $state = $context->gross ? 'gross' : 'net';
        $last = end($parts);
        if ($last === 'gross' || $last === 'net') {
            $state = $last;
            array_pop($parts);
        }

        $accessor = [$state];
        $last = end($parts);
        if ($last === 'listPrice' || $last === 'percentage') {
            $accessor = [$last, $state];
            array_pop($parts);
        }

        if ($parts !== []) {
            throw NotSupportedByEngine::field($field, 'unknown cheapest price accessor');
        }

        $paths = [];
        foreach ([...$context->ruleIds, 'default'] as $ruleKey) {
            $paths[] = ['cheapestPrice', $ruleKey, $context->currencyId, ...$accessor];
        }

        return new ResolvedField($field, FieldType::FLOAT, $paths);
    }
}

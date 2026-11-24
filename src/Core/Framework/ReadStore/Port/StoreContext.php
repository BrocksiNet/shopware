<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Port;

use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Everything a query needs to resolve the contextual fields of a document (translations, prices, visibility). Ids are lower case hex.
 *
 * `rounding*` is the item rounding the context uses (currency, possibly overwritten per country), `currencyRounding*` the item rounding of
 * the currency itself, which the document builder used for `cheapestPrice`. When they differ, `cheapestPrice` cannot be answered from the document.
 *
 * @internal
 */
#[Package('framework')]
final readonly class StoreContext
{
    /**
     * @param list<string> $ruleIds in the order of the context, the first match wins for `cheapestPrice`
     */
    public function __construct(
        public string $languageId,
        public string $salesChannelId,
        public string $currencyId,
        public array $ruleIds = [],
        public bool $gross = true,
        public int $roundingDecimals = 2,
        public float $roundingInterval = 0.01,
        public bool $roundForNet = true,
        public int $currencyRoundingDecimals = 2,
        public float $currencyRoundingInterval = 0.01,
        public bool $currencyRoundForNet = true,
    ) {
    }

    public static function fromSalesChannelContext(SalesChannelContext $context): self
    {
        $currencyRounding = $context->getCurrency()->getItemRounding();
        $rounding = $context->getItemRounding();

        return new self(
            languageId: $context->getLanguageId(),
            salesChannelId: $context->getSalesChannelId(),
            currencyId: $context->getCurrencyId(),
            ruleIds: array_values($context->getContext()->getRuleIds()),
            gross: $context->getTaxState() === CartPrice::TAX_STATE_GROSS,
            roundingDecimals: $rounding->getDecimals(),
            roundingInterval: $rounding->getInterval(),
            roundForNet: $rounding->roundForNet(),
            currencyRoundingDecimals: $currencyRounding->getDecimals(),
            currencyRoundingInterval: $currencyRounding->getInterval(),
            currencyRoundForNet: $currencyRounding->roundForNet(),
        );
    }

    public function roundsLikeCurrency(): bool
    {
        return $this->roundingDecimals === $this->currencyRoundingDecimals
            && abs($this->roundingInterval - $this->currencyRoundingInterval) < 1e-9
            && $this->roundForNet === $this->currencyRoundForNet;
    }
}

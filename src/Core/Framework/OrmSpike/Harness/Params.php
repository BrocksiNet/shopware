<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\Harness;

use Shopware\Core\Framework\Log\Package;

/**
 * The fixed parameters of the scenarios (hex ids). Ids that come from the Shopware installation (sales channel, language, tax)
 * are looked up once by {@see ParamsFactory}, they differ per installation but are the same for all implementations.
 *
 * @internal
 */
#[Package('framework')]
final readonly class Params
{
    /**
     * @param list<string> $manufacturerIds
     * @param array<string, mixed> $criteriaJson
     */
    public function __construct(
        public string $salesChannelId,
        public string $languageId,
        public string $systemLanguageId,
        public string $currencyId,
        public string $taxId,
        public string $ruleId,
        public string $liveVersionId,
        public string $categoryId,
        public int $page,
        public int $limit,
        public string $searchTerm,
        public array $manufacturerIds,
        public string $variantId,
        public array $criteriaJson,
    ) {
    }

    /**
     * Language chain of the context: requested language first, then the system language.
     *
     * @return list<string>
     */
    public function languageChain(): array
    {
        return [$this->languageId, $this->systemLanguageId];
    }
}

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
     * @param list<string> $mgetIds R4 ids, in the order the result must have
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
        public string $searchWord = '',
        public array $mgetIds = [],
    ) {
    }

    /**
     * A copy with some parameters replaced (the load command varies ids and terms per request).
     *
     * @param array<string, mixed> $overrides constructor argument name => value
     */
    public function with(array $overrides): self
    {
        return new self(...array_merge(get_object_vars($this), $overrides));
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

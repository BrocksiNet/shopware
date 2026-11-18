<?php declare(strict_types=1);

namespace Shopware\Core\Content\Newsletter\Domain;

use Shopware\Core\Framework\Log\Package;

/**
 * @internal
 */
#[Package('after-sales')]
final readonly class NewsletterSalutation
{
    public function __construct(
        public string $id,
        public string $salutationKey,
        public ?string $displayName,
        public ?string $letterName,
    ) {
    }
}

<?php declare(strict_types=1);

namespace Shopware\Core\Content\Newsletter\Domain;

use Shopware\Core\Framework\Log\Package;

/**
 * Data to create or update a recipient. A `null` optional field means "not provided" and keeps the stored value.
 *
 * @internal
 */
#[Package('after-sales')]
final readonly class NewsletterRecipientWrite
{
    /**
     * @param array<string, mixed>|null $customFields
     */
    public function __construct(
        public string $id,
        public string $email,
        public NewsletterStatus $status,
        public string $hash,
        public string $languageId,
        public string $salesChannelId,
        public ?string $title = null,
        public ?string $firstName = null,
        public ?string $lastName = null,
        public ?string $zipCode = null,
        public ?string $city = null,
        public ?string $street = null,
        public ?string $salutationId = null,
        public ?array $customFields = null,
    ) {
    }
}

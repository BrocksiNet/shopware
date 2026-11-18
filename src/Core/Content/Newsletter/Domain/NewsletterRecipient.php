<?php declare(strict_types=1);

namespace Shopware\Core\Content\Newsletter\Domain;

use Shopware\Core\Framework\Log\Package;

/**
 * Read model of a newsletter recipient, independent of the DAL entity.
 *
 * @internal
 */
#[Package('after-sales')]
final readonly class NewsletterRecipient
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
        public ?\DateTimeImmutable $confirmedAt = null,
        public ?NewsletterSalutation $salutation = null,
        public ?array $customFields = null,
        public ?\DateTimeImmutable $createdAt = null,
        public ?\DateTimeImmutable $updatedAt = null,
    ) {
    }
}

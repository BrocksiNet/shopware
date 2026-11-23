<?php declare(strict_types=1);

namespace Shopware\Core\Content\Newsletter\Domain;

use Shopware\Core\Framework\Log\Package;

/**
 * Data to create or update a recipient.
 *
 * Presence and value of an optional field are separate: a field listed in `$provided` is written, also as `null` (clears the stored value),
 * a field not listed keeps the stored value.
 * Without `$provided` (null) every non-null optional field counts as provided.
 *
 * @internal
 */
#[Package('after-sales')]
final readonly class NewsletterRecipientWrite
{
    /**
     * @param array<string, mixed>|null $customFields
     * @param list<string>|null $provided names of the optional properties to write, see the class comment
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
        public ?array $provided = null,
    ) {
    }

    public function isProvided(string $field): bool
    {
        if ($this->provided === null) {
            return match ($field) {
                'title' => $this->title,
                'firstName' => $this->firstName,
                'lastName' => $this->lastName,
                'zipCode' => $this->zipCode,
                'city' => $this->city,
                'street' => $this->street,
                'salutationId' => $this->salutationId,
                'customFields' => $this->customFields,
                default => null,
            } !== null;
        }

        return \in_array($field, $this->provided, true);
    }
}

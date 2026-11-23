<?php declare(strict_types=1);

namespace Shopware\Core\Content\Newsletter\Domain\Request;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;

/**
 * Typed view of the validated subscribe payload.
 * Validation stays in the DataValidator, so `BuildValidationEvent` subscribers and the error format keep working.
 *
 * @internal
 */
#[Package('after-sales')]
final readonly class SubscribeRequest
{
    public const OPTIONAL_FIELDS = ['title', 'firstName', 'lastName', 'zipCode', 'city', 'street', 'salutationId', 'customFields'];

    /**
     * @param list<string> $present optional fields sent in the payload, also when sent as `null`
     */
    public function __construct(
        public string $email,
        public string $option,
        public ?string $storefrontUrl = null,
        public ?string $title = null,
        public ?string $firstName = null,
        public ?string $lastName = null,
        public ?string $zipCode = null,
        public ?string $city = null,
        public ?string $street = null,
        public ?string $salutationId = null,
        public ?RequestDataBag $customFields = null,
        public array $present = [],
    ) {
    }

    public static function fromDataBag(RequestDataBag $bag): self
    {
        $custom = $bag->get('customFields');

        return new self(
            email: self::string($bag, 'email') ?? '',
            option: self::string($bag, 'option') ?? '',
            storefrontUrl: self::string($bag, 'storefrontUrl'),
            title: self::string($bag, 'title'),
            firstName: self::string($bag, 'firstName'),
            lastName: self::string($bag, 'lastName'),
            zipCode: self::string($bag, 'zipCode'),
            city: self::string($bag, 'city'),
            street: self::string($bag, 'street'),
            salutationId: self::string($bag, 'salutationId'),
            customFields: $custom instanceof RequestDataBag ? $custom : null,
            present: array_values(array_filter(self::OPTIONAL_FIELDS, $bag->has(...))),
        );
    }

    private static function string(RequestDataBag $bag, string $key): ?string
    {
        $value = $bag->get($key);

        return \is_scalar($value) ? (string) $value : null;
    }
}

<?php declare(strict_types=1);

namespace Shopware\Core\Content\Newsletter\Domain;

use Shopware\Core\Content\Newsletter\Aggregate\NewsletterRecipient\NewsletterRecipientEntity;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\System\Salutation\SalutationEntity;

/**
 * Bridge for the public events, which still carry a `NewsletterRecipientEntity`.
 * Drop it once the events carry the DTO.
 *
 * @internal
 */
#[Package('after-sales')]
final class NewsletterRecipientEntityFactory
{
    public static function create(NewsletterRecipient $recipient): NewsletterRecipientEntity
    {
        $entity = new NewsletterRecipientEntity();
        $entity->setUniqueIdentifier($recipient->id);
        $entity->setId($recipient->id);
        $entity->setEmail($recipient->email);
        $entity->setStatus($recipient->status->value);
        $entity->setHash($recipient->hash);
        $entity->setLanguageId($recipient->languageId);
        $entity->setSalesChannelId($recipient->salesChannelId);
        if ($recipient->title !== null) {
            $entity->setTitle($recipient->title);
        }
        if ($recipient->firstName !== null) {
            $entity->setFirstName($recipient->firstName);
        }
        if ($recipient->lastName !== null) {
            $entity->setLastName($recipient->lastName);
        }
        if ($recipient->zipCode !== null) {
            $entity->setZipCode($recipient->zipCode);
        }
        if ($recipient->city !== null) {
            $entity->setCity($recipient->city);
        }
        if ($recipient->street !== null) {
            $entity->setStreet($recipient->street);
        }
        if ($recipient->salutationId !== null) {
            $entity->setSalutationId($recipient->salutationId);
        }
        if ($recipient->confirmedAt !== null) {
            $entity->setConfirmedAt($recipient->confirmedAt);
        }
        $entity->setCustomFields($recipient->customFields);

        if ($recipient->salutation !== null) {
            $salutation = new SalutationEntity();
            $salutation->setUniqueIdentifier($recipient->salutation->id);
            $salutation->setId($recipient->salutation->id);
            $salutation->setSalutationKey($recipient->salutation->salutationKey);
            $salutation->setDisplayName($recipient->salutation->displayName);
            $salutation->setLetterName($recipient->salutation->letterName);
            $salutation->setTranslated([
                'displayName' => $recipient->salutation->displayName,
                'letterName' => $recipient->salutation->letterName,
            ]);
            $entity->setSalutation($salutation);
        }

        return $entity;
    }
}

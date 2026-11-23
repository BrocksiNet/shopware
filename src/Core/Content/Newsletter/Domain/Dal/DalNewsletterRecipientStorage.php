<?php declare(strict_types=1);

namespace Shopware\Core\Content\Newsletter\Domain\Dal;

use Shopware\Core\Checkout\Customer\CustomerCollection;
use Shopware\Core\Content\Newsletter\Aggregate\NewsletterRecipient\NewsletterRecipientCollection;
use Shopware\Core\Content\Newsletter\Aggregate\NewsletterRecipient\NewsletterRecipientEntity;
use Shopware\Core\Content\Newsletter\Domain\NewsletterRecipient;
use Shopware\Core\Content\Newsletter\Domain\NewsletterRecipientEntityFactory;
use Shopware\Core\Content\Newsletter\Domain\NewsletterRecipientStorage;
use Shopware\Core\Content\Newsletter\Domain\NewsletterRecipientWrite;
use Shopware\Core\Content\Newsletter\Domain\NewsletterSalutation;
use Shopware\Core\Content\Newsletter\Domain\NewsletterStatus;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Log\Package;

/**
 * Storage on top of the DAL, works on every database the DAL supports and fires the written events and indexers.
 *
 * @internal
 */
#[Package('after-sales')]
final class DalNewsletterRecipientStorage implements NewsletterRecipientStorage
{
    /**
     * The entity each returned DTO was built from, for {@see toEventEntity()}; entries go away with the DTO.
     *
     * @var \WeakMap<NewsletterRecipient, NewsletterRecipientEntity>
     */
    private readonly \WeakMap $entities;

    /**
     * @param EntityRepository<NewsletterRecipientCollection> $newsletterRecipientRepository
     * @param EntityRepository<CustomerCollection> $customerRepository
     *
     * @internal
     */
    public function __construct(
        private readonly EntityRepository $newsletterRecipientRepository,
        private readonly EntityRepository $customerRepository,
    ) {
        $this->entities = new \WeakMap();
    }

    public function findByEmail(string $email, string $salesChannelId, Context $context): ?NewsletterRecipient
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('email', $email), new EqualsFilter('salesChannelId', $salesChannelId));

        return $this->first($criteria, $context);
    }

    public function findByHash(string $hash, Context $context): ?NewsletterRecipient
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('hash', $hash));

        return $this->first($criteria, $context);
    }

    public function upsert(NewsletterRecipientWrite $write, Context $context): void
    {
        $data = [
            'id' => $write->id,
            'email' => $write->email,
            'status' => $write->status->value,
            'hash' => $write->hash,
            'languageId' => $write->languageId,
            'salesChannelId' => $write->salesChannelId,
        ];

        $optional = [
            'title' => $write->title,
            'firstName' => $write->firstName,
            'lastName' => $write->lastName,
            'zipCode' => $write->zipCode,
            'city' => $write->city,
            'street' => $write->street,
            'salutationId' => $write->salutationId,
            'customFields' => $write->customFields,
        ];

        $this->newsletterRecipientRepository->upsert([$data + array_filter($optional, $write->isProvided(...), \ARRAY_FILTER_USE_KEY)], $context);
    }

    public function confirm(string $id, \DateTimeImmutable $confirmedAt, Context $context): void
    {
        $this->newsletterRecipientRepository->update([[
            'id' => $id,
            'status' => NewsletterStatus::OptIn->value,
            'confirmedAt' => $confirmedAt,
        ]], $context);
    }

    public function optOut(string $id, Context $context): void
    {
        $this->newsletterRecipientRepository->update([[
            'id' => $id,
            'status' => NewsletterStatus::OptOut->value,
        ]], $context);
    }

    public function findCustomerEmail(string $customerId, Context $context): ?string
    {
        return $this->customerRepository->search(new Criteria([$customerId]), $context)->getEntities()->first()?->getEmail();
    }

    public function toEventEntity(NewsletterRecipient $recipient): NewsletterRecipientEntity
    {
        return $this->entities[$recipient] ?? NewsletterRecipientEntityFactory::create($recipient);
    }

    private function first(Criteria $criteria, Context $context): ?NewsletterRecipient
    {
        $criteria->addAssociation('salutation');
        $criteria->setLimit(1);

        $entity = $this->newsletterRecipientRepository->search($criteria, $context)->getEntities()->first();

        if ($entity === null) {
            return null;
        }

        $recipient = self::toDto($entity);
        $this->entities[$recipient] = $entity;

        return $recipient;
    }

    private static function toDto(NewsletterRecipientEntity $entity): NewsletterRecipient
    {
        $salutation = $entity->getSalutation();
        $confirmedAt = $entity->getConfirmedAt();

        return new NewsletterRecipient(
            id: $entity->getId(),
            email: $entity->getEmail(),
            status: NewsletterStatus::from($entity->getStatus() ?? NewsletterStatus::NotSet->value),
            hash: $entity->getHash(),
            languageId: $entity->getLanguageId(),
            salesChannelId: $entity->getSalesChannelId(),
            title: $entity->getTitle(),
            firstName: $entity->getFirstName(),
            lastName: $entity->getLastName(),
            zipCode: $entity->getZipCode(),
            city: $entity->getCity(),
            street: $entity->getStreet(),
            salutationId: $entity->getSalutationId(),
            confirmedAt: $confirmedAt === null ? null : \DateTimeImmutable::createFromInterface($confirmedAt),
            salutation: $salutation === null ? null : new NewsletterSalutation(
                $salutation->getId(),
                $salutation->getSalutationKey() ?? '',
                $salutation->getTranslation('displayName') ?? $salutation->getDisplayName(),
                $salutation->getTranslation('letterName') ?? $salutation->getLetterName(),
            ),
            customFields: $entity->getCustomFields(),
            createdAt: $entity->getCreatedAt() === null ? null : \DateTimeImmutable::createFromInterface($entity->getCreatedAt()),
            updatedAt: $entity->getUpdatedAt() === null ? null : \DateTimeImmutable::createFromInterface($entity->getUpdatedAt()),
        );
    }
}

<?php declare(strict_types=1);

namespace Shopware\Core\Content\Newsletter\Domain;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Log\Package;

/**
 * Storage port of the newsletter routes: no Criteria, no SQL in the signatures.
 *
 * @internal
 */
#[Package('after-sales')]
interface NewsletterRecipientStorage
{
    public function findByEmail(string $email, string $salesChannelId, Context $context): ?NewsletterRecipient;

    public function findByHash(string $hash, Context $context): ?NewsletterRecipient;

    public function upsert(NewsletterRecipientWrite $write, Context $context): void;

    public function confirm(string $id, \DateTimeImmutable $confirmedAt, Context $context): void;

    public function optOut(string $id, Context $context): void;

    public function findCustomerEmail(string $customerId, Context $context): ?string;
}

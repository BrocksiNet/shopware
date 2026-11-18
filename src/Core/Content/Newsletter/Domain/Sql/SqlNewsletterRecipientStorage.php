<?php declare(strict_types=1);

namespace Shopware\Core\Content\Newsletter\Domain\Sql;

use Doctrine\DBAL\Connection;
use Shopware\Core\Content\Newsletter\Domain\NewsletterRecipient;
use Shopware\Core\Content\Newsletter\Domain\NewsletterRecipientStorage;
use Shopware\Core\Content\Newsletter\Domain\NewsletterRecipientWrite;
use Shopware\Core\Content\Newsletter\Domain\NewsletterSalutation;
use Shopware\Core\Content\Newsletter\Domain\NewsletterStatus;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Plain SQL storage, written in the MySQL dialect that the PostgreSQL layer translates, so there is no platform branch.
 * It does not dispatch written events and does not run indexers (for example the customer's newsletter sales channel ids).
 *
 * @internal
 */
#[Package('after-sales')]
final class SqlNewsletterRecipientStorage implements NewsletterRecipientStorage
{
    private const SELECT = <<<'SQL'
        SELECT LOWER(HEX(r.`id`)) AS id, r.`email`, r.`status`, r.`hash`,
               LOWER(HEX(r.`language_id`)) AS language_id, LOWER(HEX(r.`sales_channel_id`)) AS sales_channel_id,
               r.`title`, r.`first_name`, r.`last_name`, r.`zip_code`, r.`city`, r.`street`,
               LOWER(HEX(r.`salutation_id`)) AS salutation_id, r.`confirmed_at`, r.`custom_fields`,
               s.`salutation_key`,
               COALESCE(st.`display_name`, sd.`display_name`) AS display_name,
               COALESCE(st.`letter_name`, sd.`letter_name`) AS letter_name
        FROM `newsletter_recipient` r
        LEFT JOIN `salutation` s ON s.`id` = r.`salutation_id`
        LEFT JOIN `salutation_translation` st ON st.`salutation_id` = s.`id` AND st.`language_id` = :languageId
        LEFT JOIN `salutation_translation` sd ON sd.`salutation_id` = s.`id` AND sd.`language_id` = :defaultLanguageId
        SQL;

    /**
     * @internal
     */
    public function __construct(private readonly Connection $connection)
    {
    }

    public function findByEmail(string $email, string $salesChannelId, Context $context): ?NewsletterRecipient
    {
        return $this->first(
            'r.`email` = :email AND r.`sales_channel_id` = :salesChannelId',
            ['email' => $email, 'salesChannelId' => Uuid::fromHexToBytes($salesChannelId)],
            $context,
        );
    }

    public function findByHash(string $hash, Context $context): ?NewsletterRecipient
    {
        return $this->first('r.`hash` = :hash', ['hash' => $hash], $context);
    }

    public function upsert(NewsletterRecipientWrite $write, Context $context): void
    {
        $values = [
            'id' => Uuid::fromHexToBytes($write->id),
            'email' => $write->email,
            'status' => $write->status->value,
            'hash' => $write->hash,
            'language_id' => Uuid::fromHexToBytes($write->languageId),
            'sales_channel_id' => Uuid::fromHexToBytes($write->salesChannelId),
        ];

        $optional = [
            'title' => $write->title,
            'first_name' => $write->firstName,
            'last_name' => $write->lastName,
            'zip_code' => $write->zipCode,
            'city' => $write->city,
            'street' => $write->street,
            'salutation_id' => $write->salutationId === null ? null : Uuid::fromHexToBytes($write->salutationId),
            'custom_fields' => $write->customFields === null ? null : json_encode($write->customFields, \JSON_THROW_ON_ERROR),
        ];

        $values += array_filter($optional, static fn ($v) => $v !== null);

        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(Defaults::STORAGE_DATE_TIME_FORMAT);
        $columns = array_keys($values);
        $updates = array_map(static fn (string $c) => \sprintf('`%1$s` = VALUES(`%1$s`)', $c), array_values(array_diff($columns, ['id'])));
        $updates[] = '`updated_at` = :updatedAt';

        $this->connection->executeStatement(
            \sprintf(
                'INSERT INTO `newsletter_recipient` (%s, `created_at`) VALUES (%s, :createdAt) ON DUPLICATE KEY UPDATE %s',
                implode(', ', array_map(static fn (string $c) => '`' . $c . '`', $columns)),
                implode(', ', array_map(static fn (string $c) => ':' . $c, $columns)),
                implode(', ', $updates),
            ),
            $values + ['createdAt' => $now, 'updatedAt' => $now],
        );
    }

    public function confirm(string $id, \DateTimeImmutable $confirmedAt, Context $context): void
    {
        $this->connection->executeStatement(
            'UPDATE `newsletter_recipient` SET `status` = :status, `confirmed_at` = :confirmedAt, `updated_at` = :updatedAt WHERE `id` = :id',
            [
                'status' => NewsletterStatus::OptIn->value,
                'confirmedAt' => $confirmedAt->setTimezone(new \DateTimeZone('UTC'))->format(Defaults::STORAGE_DATE_TIME_FORMAT),
                'updatedAt' => self::now(),
                'id' => Uuid::fromHexToBytes($id),
            ],
        );
    }

    public function optOut(string $id, Context $context): void
    {
        $this->connection->executeStatement(
            'UPDATE `newsletter_recipient` SET `status` = :status, `updated_at` = :updatedAt WHERE `id` = :id',
            ['status' => NewsletterStatus::OptOut->value, 'updatedAt' => self::now(), 'id' => Uuid::fromHexToBytes($id)],
        );
    }

    public function findCustomerEmail(string $customerId, Context $context): ?string
    {
        $email = $this->connection->fetchOne('SELECT `email` FROM `customer` WHERE `id` = :id', ['id' => Uuid::fromHexToBytes($customerId)]);

        return \is_string($email) ? $email : null;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function first(string $where, array $params, Context $context): ?NewsletterRecipient
    {
        $row = $this->connection->fetchAssociative(
            self::SELECT . ' WHERE ' . $where . ' LIMIT 1',
            $params + [
                'languageId' => Uuid::fromHexToBytes($context->getLanguageId()),
                'defaultLanguageId' => Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM),
            ],
        );

        if ($row === false) {
            return null;
        }

        $custom = $row['custom_fields'] === null ? null : json_decode((string) $row['custom_fields'], true, 512, \JSON_THROW_ON_ERROR);

        return new NewsletterRecipient(
            id: $row['id'],
            email: $row['email'],
            status: NewsletterStatus::from($row['status']),
            hash: $row['hash'],
            languageId: $row['language_id'],
            salesChannelId: $row['sales_channel_id'],
            title: $row['title'],
            firstName: $row['first_name'],
            lastName: $row['last_name'],
            zipCode: $row['zip_code'],
            city: $row['city'],
            street: $row['street'],
            salutationId: $row['salutation_id'],
            confirmedAt: $row['confirmed_at'] === null ? null : new \DateTimeImmutable((string) $row['confirmed_at'], new \DateTimeZone('UTC')),
            salutation: $row['salutation_id'] === null || $row['salutation_key'] === null
                ? null
                : new NewsletterSalutation($row['salutation_id'], $row['salutation_key'], $row['display_name'], $row['letter_name']),
            customFields: \is_array($custom) ? $custom : null,
        );
    }

    private static function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(Defaults::STORAGE_DATE_TIME_FORMAT);
    }
}

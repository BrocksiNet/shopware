<?php declare(strict_types=1);

namespace Shopware\Core\Content\Newsletter\Domain\Sql;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Shopware\Core\Content\Newsletter\Domain\NewsletterRecipient;
use Shopware\Core\Content\Newsletter\Domain\NewsletterRecipientStorage;
use Shopware\Core\Content\Newsletter\Domain\NewsletterRecipientWrite;
use Shopware\Core\Content\Newsletter\Domain\NewsletterSalutation;
use Shopware\Core\Content\Newsletter\Domain\NewsletterStatus;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Adapter\Database\Dialect\IdBinding;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Log\Package;

/**
 * Plain SQL storage, written in the MySQL dialect that the PostgreSQL layer translates, so there is no platform branch.
 * It does not dispatch written events and does not run indexers (for example the customer's newsletter sales channel ids).
 *
 * @internal
 */
#[Package('after-sales')]
final class SqlNewsletterRecipientStorage implements NewsletterRecipientStorage
{
    /**
     * @internal
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly ClockInterface $clock,
    ) {
    }

    public function findByEmail(string $email, string $salesChannelId, Context $context): ?NewsletterRecipient
    {
        return $this->first(
            'r.`email` = :email AND r.`sales_channel_id` = :salesChannelId',
            ['email' => $email, 'salesChannelId' => IdBinding::value($salesChannelId)],
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
            'id' => IdBinding::value($write->id),
            'email' => $write->email,
            'status' => $write->status->value,
            'hash' => $write->hash,
            'language_id' => IdBinding::value($write->languageId),
            'sales_channel_id' => IdBinding::value($write->salesChannelId),
        ];

        $optional = [
            'title' => $write->title,
            'first_name' => $write->firstName,
            'last_name' => $write->lastName,
            'zip_code' => $write->zipCode,
            'city' => $write->city,
            'street' => $write->street,
            'salutation_id' => $write->salutationId === null ? null : IdBinding::value($write->salutationId),
            'custom_fields' => $write->customFields === null ? null : json_encode($write->customFields, \JSON_THROW_ON_ERROR),
        ];

        $values += array_filter($optional, static fn ($v) => $v !== null);

        $now = $this->now();
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
                'updatedAt' => $this->now(),
                'id' => IdBinding::value($id),
            ],
        );
    }

    public function optOut(string $id, Context $context): void
    {
        $this->connection->executeStatement(
            'UPDATE `newsletter_recipient` SET `status` = :status, `updated_at` = :updatedAt WHERE `id` = :id',
            ['status' => NewsletterStatus::OptOut->value, 'updatedAt' => $this->now(), 'id' => IdBinding::value($id)],
        );
    }

    public function findCustomerEmail(string $customerId, Context $context): ?string
    {
        $email = $this->connection->fetchOne('SELECT `email` FROM `customer` WHERE `id` = :id', ['id' => IdBinding::value($customerId)]);

        return \is_string($email) ? $email : null;
    }

    private static function select(): string
    {
        return 'SELECT ' . IdBinding::text('r.`id`') . ' AS id, r.`email`, r.`status`, r.`hash`, '
            . IdBinding::text('r.`language_id`') . ' AS language_id, ' . IdBinding::text('r.`sales_channel_id`') . ' AS sales_channel_id, '
            . 'r.`title`, r.`first_name`, r.`last_name`, r.`zip_code`, r.`city`, r.`street`, '
            . IdBinding::text('r.`salutation_id`') . ' AS salutation_id, r.`confirmed_at`, r.`custom_fields`, r.`created_at`, r.`updated_at`, '
            . 's.`salutation_key` '
            . 'FROM `newsletter_recipient` r '
            . 'LEFT JOIN `salutation` s ON s.`id` = r.`salutation_id`';
    }

    /**
     * @param array<string, mixed> $params
     */
    private function first(string $where, array $params, Context $context): ?NewsletterRecipient
    {
        $row = $this->connection->fetchAssociative(
            self::select() . ' WHERE ' . $where . ' LIMIT 1',
            $params,
        );

        if ($row === false) {
            return null;
        }

        $translation = $row['salutation_id'] === null ? null : $this->salutationTranslation($row['salutation_id'], $context);
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
                : new NewsletterSalutation($row['salutation_id'], $row['salutation_key'], $translation['display_name'] ?? null, $translation['letter_name'] ?? null),
            customFields: \is_array($custom) ? $custom : null,
            createdAt: self::date($row['created_at']),
            updatedAt: self::date($row['updated_at']),
        );
    }

    /**
     * Follows the language chain (context language, parents, system language) like the DAL translation inheritance.
     * The first language of the chain that has a translation wins, the fields are taken from it and filled up from the next ones.
     *
     * @return array{display_name: ?string, letter_name: ?string}
     */
    private function salutationTranslation(string $salutationId, Context $context): array
    {
        $chain = $context->getLanguageIdChain();
        $rows = $this->connection->fetchAllAssociative(
            'SELECT ' . IdBinding::text('`language_id`') . ' AS language_id, `display_name`, `letter_name` FROM `salutation_translation` WHERE `salutation_id` = :id AND `language_id` IN (:languages)',
            ['id' => IdBinding::value($salutationId), 'languages' => array_map(IdBinding::value(...), $chain)],
            ['languages' => IdBinding::listType()],
        );
        $byLanguage = array_column($rows, null, 'language_id');

        $result = ['display_name' => null, 'letter_name' => null];
        foreach ($chain as $languageId) {
            $result['display_name'] ??= $byLanguage[$languageId]['display_name'] ?? null;
            $result['letter_name'] ??= $byLanguage[$languageId]['letter_name'] ?? null;
        }

        return $result;
    }

    private static function date(mixed $value): ?\DateTimeImmutable
    {
        return \is_string($value) ? new \DateTimeImmutable($value, new \DateTimeZone('UTC')) : null;
    }

    private function now(): string
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format(Defaults::STORAGE_DATE_TIME_FORMAT);
    }
}

<?php declare(strict_types=1);

namespace Shopware\Core\System\SalesChannel\Context;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Clock\ClockInterface;
use Shopware\Core\Checkout\Cart\AbstractCartPersister;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Adapter\Database\Dialect\IdBinding;
use Shopware\Core\Framework\DataAbstractionLayer\Doctrine\RetryableQuery;
use Shopware\Core\Framework\DataAbstractionLayer\Doctrine\RetryableTransaction;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Util\Random;
use Shopware\Core\System\SalesChannel\Event\SalesChannelContextTokenChangeEvent;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[Package('framework')]
class SalesChannelContextPersister
{
    private const MAX_UNIQUE_RETRIES = 10;

    private readonly string $lifetimeInterval;

    /**
     * @internal
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly AbstractCartPersister $cartPersister,
        private readonly ClockInterface $clock,
        ?string $lifetimeInterval = 'P1D',
    ) {
        $this->lifetimeInterval = $lifetimeInterval ?? 'P1D';
    }

    /**
     * @codeCoverageIgnore
     *
     * @see \Shopware\Tests\Integration\Core\System\SalesChannel\Context\SalesChannelContextPersisterTest
     *
     * @param array<string, mixed> $newParameters
     */
    public function save(string $token, array $newParameters, string $salesChannelId, ?string $customerId = null): void
    {
        $existing = $this->load($token, $salesChannelId, $customerId);

        $parameters = array_replace_recursive($existing, $newParameters);
        if (isset($newParameters['permissions']) && $newParameters['permissions'] === []) {
            $parameters['permissions'] = [];
        }

        unset($parameters['token']);

        $data = [
            'token' => $token,
            'payload' => json_encode($parameters, \JSON_THROW_ON_ERROR),
            'salesChannelId' => $salesChannelId ? IdBinding::value($salesChannelId) : null,
            'customerId' => $customerId ? IdBinding::value($customerId) : null,
            'updatedAt' => $this->clock->now()->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ];

        // Update in place for the usual single-key conflict to avoid REPLACE's delete and insert lock pattern.
        $query = new RetryableQuery(
            $this->connection,
            $this->connection->prepare(<<<'SQL'
                INSERT INTO sales_channel_api_context (`token`, `payload`, `sales_channel_id`, `customer_id`, `updated_at`)
                VALUES (:token, :payload, :salesChannelId, :customerId, :updatedAt)
                ON DUPLICATE KEY UPDATE
                    `token` = :token,
                    `payload` = :payload,
                    `sales_channel_id` = :salesChannelId,
                    `customer_id` = :customerId,
                    `updated_at` = :updatedAt
                SQL)
        );

        try {
            // in its own transaction (a savepoint when one is open): PostgreSQL aborts the whole open transaction after a failed statement,
            // so the DELETE and INSERT below would fail, too
            $this->connection->transactional(static fn () => $query->execute($data));
        } catch (UniqueConstraintViolationException) {
            // The other unique key (sales channel and customer) conflicts, or both do: remove the conflicting rows and insert,
            // the same result as REPLACE. REPLACE itself is not an option, PostgreSQL can only resolve a conflict on one key.
            // Concurrent requests can insert the same token or sales channel and customer between our DELETE and INSERT.
            // The loser sees a unique violation (PostgreSQL does not hide it like MySQL's gap locks do), so retry: the next DELETE removes the winner's row.
            for ($attempt = 1;; ++$attempt) {
                try {
                    RetryableTransaction::retryable($this->connection, function () use ($data): void {
                        $this->connection->executeStatement(
                            'DELETE FROM sales_channel_api_context WHERE `token` = :token OR (`sales_channel_id` = :salesChannelId AND `customer_id` = :customerId)',
                            ['token' => $data['token'], 'salesChannelId' => $data['salesChannelId'], 'customerId' => $data['customerId']]
                        );
                        $this->connection->executeStatement(
                            'INSERT INTO sales_channel_api_context (`token`, `payload`, `sales_channel_id`, `customer_id`, `updated_at`)
                        VALUES (:token, :payload, :salesChannelId, :customerId, :updatedAt)',
                            $data
                        );
                    });

                    return;
                } catch (UniqueConstraintViolationException $e) {
                    if ($attempt >= self::MAX_UNIQUE_RETRIES) {
                        throw $e;
                    }

                    // Back off a little so that contending requests do not keep colliding in lockstep.
                    usleep(random_int(1000, 20000) * $attempt);
                }
            }
        }
    }

    public function delete(string $token, string $salesChannelId, ?string $customerId = null): void
    {
        $this->connection->executeStatement(
            'DELETE FROM sales_channel_api_context WHERE token = :token',
            [
                'token' => $token,
            ]
        );
    }

    public function replace(string $oldToken, SalesChannelContext $context): string
    {
        $newToken = Random::getAlphanumericString(32);

        $affected = $this->connection->executeStatement(
            'UPDATE `sales_channel_api_context`
                   SET `token` = :newToken,
                       `updated_at` = :updatedAt
                   WHERE `token` = :oldToken',
            [
                'newToken' => $newToken,
                'oldToken' => $oldToken,
                'updatedAt' => $this->clock->now()->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            ]
        );

        if ($affected === 0) {
            $customer = $context->getCustomer();

            $this->connection->insert('sales_channel_api_context', [
                'token' => $newToken,
                'payload' => json_encode([]),
                'sales_channel_id' => IdBinding::value($context->getSalesChannelId()),
                'customer_id' => $customer ? IdBinding::value($customer->getId()) : null,
                'updated_at' => $this->clock->now()->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            ]);
        }

        $this->cartPersister->replace($oldToken, $newToken, $context);

        $context->assign(['token' => $newToken]);
        $this->eventDispatcher->dispatch(new SalesChannelContextTokenChangeEvent($context, $oldToken, $newToken));

        return $newToken;
    }

    /**
     * @return array<string, mixed>
     */
    public function load(string $token, string $salesChannelId, ?string $customerId = null): array
    {
        $qb = $this->connection->createQueryBuilder();

        $qb->select('token', 'payload', 'updated_at', IdBinding::text('sales_channel_id') . ' AS sales_channel_id', IdBinding::text('customer_id') . ' AS customer_id');
        $qb->from('sales_channel_api_context');

        $qb->where('sales_channel_id = :salesChannelId');
        $qb->setParameter('salesChannelId', IdBinding::value($salesChannelId));

        if ($customerId !== null) {
            $qb->andWhere('(token = :token OR customer_id = :customerId)');
            $qb->setParameter('token', $token);
            $qb->setParameter('customerId', IdBinding::value($customerId));
            $qb->setMaxResults(2);
        } else {
            $qb->andWhere('token = :token');
            $qb->setParameter('token', $token);
            $qb->setMaxResults(1);
        }

        $data = $qb->executeQuery()->fetchAllAssociative();

        if ($data === []) {
            return [];
        }

        $customerContext = $salesChannelId && $customerId ? $this->getCustomerContext($data, $salesChannelId, $customerId) : null;

        $context = $customerContext ?? array_shift($data);

        $updatedAt = new \DateTimeImmutable($context['updated_at']);
        $expiredTime = $updatedAt->add(new \DateInterval($this->lifetimeInterval));

        $payload = array_filter(json_decode((string) $context['payload'], true, 512, \JSON_THROW_ON_ERROR));
        $now = $this->clock->now();
        if ($expiredTime < $now) {
            // context is expired
            if ($customerId !== null) {
                $payload['expired'] = true;
            } else {
                $payload = ['expired' => true];
            }
        } else {
            $payload['expired'] = false;
        }

        $payload['token'] = $context['token'];

        if (!isset($payload[SalesChannelContextService::CUSTOMER_ID])
            && \is_string($context['customer_id'] ?? null)
            && $context['customer_id'] !== ''
        ) {
            $payload[SalesChannelContextService::CUSTOMER_ID] = IdBinding::toHex($context['customer_id']);
        }

        return $payload;
    }

    public function revokeAllCustomerTokens(string $customerId, string ...$preserveTokens): void
    {
        $revokeParams = [
            'customerId' => null,
            'billingAddressId' => null,
            'shippingAddressId' => null,
        ];

        $qb = $this->connection->createQueryBuilder();
        $qb
            ->update('sales_channel_api_context')
            ->set('payload', ':payload')
            ->set('customer_id', 'NULL')
            ->set('updated_at', ':updatedAt')
            ->where('customer_id = :customerId')
            ->setParameter('updatedAt', $this->clock->now()->format(Defaults::STORAGE_DATE_TIME_FORMAT))
            ->setParameter('payload', json_encode($revokeParams))
            ->setParameter('customerId', IdBinding::value($customerId));

        // keep tokens valid, which are given in $preserveTokens
        if ($preserveTokens) {
            $qb
                ->andWhere($qb->expr()->notIn('token', ':preserveTokens'))
                ->setParameter('preserveTokens', $preserveTokens, ArrayParameterType::STRING);
        }

        $qb->executeStatement();
    }

    /**
     * @param array<array<string, mixed>> $data
     *
     * @return array<string, mixed>|null
     */
    private function getCustomerContext(array $data, string $salesChannelId, string $customerId): ?array
    {
        foreach ($data as $row) {
            $customerIdFromRow = $row['customer_id'] ?? null;
            if (\is_string($customerIdFromRow) && $customerIdFromRow !== ''
&& IdBinding::toHex($row['sales_channel_id']) === $salesChannelId
&& IdBinding::toHex($customerIdFromRow) === $customerId
            ) {
                return $row;
            }
        }

        return null;
    }
}

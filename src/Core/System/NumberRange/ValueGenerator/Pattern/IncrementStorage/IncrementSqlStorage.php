<?php declare(strict_types=1);

namespace Shopware\Core\System\NumberRange\ValueGenerator\Pattern\IncrementStorage;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Connections\PrimaryReadReplicaConnection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Psr\Clock\ClockInterface;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Adapter\Database\Dialect\IdBinding;
use Shopware\Core\Framework\Adapter\Database\Dialect\SqlDialectFactory;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * @codeCoverageIgnore
 *
 * @see \Shopware\Tests\Integration\Core\System\NumberRange\ValueGenerator\IncrementSqlStorageTest
 */
#[Package('framework')]
class IncrementSqlStorage extends AbstractIncrementStorage
{
    /**
     * @internal
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly ClockInterface $clock
    ) {
    }

    public function reserve(array $config): int
    {
        $start = $config['start'] ?? 1;
        $varname = Uuid::randomHex();
        $stateId = Uuid::randomBytes();

        $platform = $this->connection->getDatabasePlatform();
        if ($platform instanceof PostgreSQLPlatform || $platform instanceof SQLitePlatform) {
            // PostgreSQL and SQLite have no user variables, the new value is read with RETURNING in the same statement;
            // that write runs through fetchOne, which a read replica connection would send to the read-only replica
            if ($this->connection instanceof PrimaryReadReplicaConnection) {
                $this->connection->ensureConnectedToPrimary();
            }

            return (int) $this->connection->fetchOne(
                'INSERT INTO number_range_state (id, last_value, number_range_id, created_at) VALUES (:stateId, :value, :id, :createdAt)
                ON CONFLICT (number_range_id) DO UPDATE
                SET last_value = GREATEST(number_range_state.last_value + 1, :value)
                RETURNING last_value',
                [
                    'value' => $start,
                    'id' => IdBinding::value($config['id']),
                    'stateId' => $stateId,
                    'createdAt' => $this->clock->now()->format(Defaults::STORAGE_DATE_TIME_FORMAT),
                ]
            );
        }

        $this->connection->executeStatement(
            'INSERT INTO `number_range_state` (`id`, `last_value`, `number_range_id`, `created_at`) VALUES (:stateId, :value, :id, :createdAt)
                ON DUPLICATE KEY UPDATE
                `last_value` = @nr' . $varname . ' := IF(`last_value`+1 > :value, `last_value`+1, :value)',
            [
                'value' => $start,
                'id' => IdBinding::value($config['id']),
                'stateId' => $stateId,
                'createdAt' => $this->clock->now()->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            ]
        );

        $result = $this->connection->executeQuery('SELECT @nr' . $varname);

        $lastNumber = $result->fetchOne();

        if (!$lastNumber) {
            return $start;
        }

        return (int) $lastNumber;
    }

    public function preview(array $config): int
    {
        $result = $this->connection->executeQuery(
            'SELECT `last_value` FROM `number_range_state` WHERE number_range_id = :id',
            [
                'id' => IdBinding::value($config['id']),
            ]
        );
        $lastNumber = $result->fetchOne();

        $start = (int) ($config['start'] ?? 1);

        if ($lastNumber === false || (int) $lastNumber < $start) {
            $nextNumber = $start;
        } else {
            $nextNumber = $lastNumber + 1;
        }

        return $nextNumber;
    }

    public function list(): array
    {
        /** @var array<string, string> $states */
        $states = $this->connection->fetchAllKeyValue('
            SELECT ' . IdBinding::text('`number_range_id`') . ', `last_value`
            FROM `number_range_state`
        ');

        return array_map(static fn ($state) => (int) $state, $states);
    }

    public function set(string $configurationId, int $value): void
    {
        $stateId = Uuid::randomBytes();
        $this->connection->executeStatement(
            SqlDialectFactory::forConnection($this->connection)->upsert(
                'number_range_state',
                ['id', 'last_value', 'number_range_id', 'created_at'],
                '(:stateId, :value, :id, :createdAt)',
                ['number_range_id'],
                ['last_value']
            ),
            [
                'value' => $value,
                'id' => IdBinding::value($configurationId),
                'stateId' => $stateId,
                'createdAt' => $this->clock->now()->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            ]
        );
    }

    public function increaseToAtLeast(string $configurationId, int $value): void
    {
        $stateId = Uuid::randomBytes();
        $platform = $this->connection->getDatabasePlatform();
        // PostgreSQL and SQLite have no ON DUPLICATE KEY UPDATE; the conflict target is the unique number range id
        $sql = $platform instanceof PostgreSQLPlatform || $platform instanceof SQLitePlatform
            ? 'INSERT INTO number_range_state (id, last_value, number_range_id, created_at) VALUES (:stateId, :value, :id, :createdAt)
                ON CONFLICT (number_range_id) DO UPDATE
                SET last_value = GREATEST(number_range_state.last_value, :value)'
            : 'INSERT INTO `number_range_state` (`id`, `last_value`, `number_range_id`, `created_at`) VALUES (:stateId, :value, :id, :createdAt)
                ON DUPLICATE KEY UPDATE
                `last_value` = GREATEST(`last_value`, :value)';

        $this->connection->executeStatement(
            $sql,
            [
                'value' => $value,
                'id' => IdBinding::value($configurationId),
                'stateId' => $stateId,
                'createdAt' => $this->clock->now()->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            ],
            [
                'value' => ParameterType::INTEGER,
            ],
        );
    }

    public function getDecorated(): AbstractIncrementStorage
    {
        throw new DecorationPatternException(self::class);
    }
}

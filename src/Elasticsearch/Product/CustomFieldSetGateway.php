<?php declare(strict_types=1);

namespace Shopware\Elasticsearch\Product;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Adapter\Database\Dialect\IdBinding;
use Shopware\Core\Framework\DataAbstractionLayer\Doctrine\FetchModeHelper;
use Shopware\Core\Framework\Log\Package;

/**
 * @internal
 *
 * @codeCoverageIgnore
 *
 * @see \Shopware\Tests\Integration\Elasticsearch\Product\CustomFieldSetGatewayTest
 */
#[Package('framework')]
class CustomFieldSetGateway
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @param array<string> $setIds
     *
     * @return array<string, list<array{id: string, name: string, type: string}>>
     */
    public function fetchCustomFieldsForSets(array $setIds): array
    {
        if ($setIds === []) {
            return [];
        }

        /** @var list<array{id: string, name: string, type: string}> $result */
        $result = $this->connection->fetchAllAssociative(
            '    SELECT ' . IdBinding::text('set_id') . ' as set_id, ' . IdBinding::text('id') . ' AS id, name, type
    FROM custom_field
    WHERE set_id IN (:setIds)
        AND include_in_search = 1',
            ['setIds' => IdBinding::values($setIds)],
            ['setIds' => ArrayParameterType::STRING]
        );

        /** @var array<string, list<array{id: string, name: string, type: string}>> $customFields */
        $customFields = FetchModeHelper::group($result);

        return $customFields;
    }

    /**
     * @param array<string> $setIds
     *
     * @return array<string>
     */
    public function fetchAppOwnedFieldSetIds(array $setIds): array
    {
        if ($setIds === []) {
            return [];
        }

        return $this->connection->fetchFirstColumn(
            'SELECT ' . IdBinding::text('id') . ' FROM custom_field_set WHERE id IN (:ids) AND app_id IS NOT NULL',
            ['ids' => IdBinding::values($setIds)],
            ['ids' => ArrayParameterType::STRING]
        );
    }

    /**
     * @param array<string> $customFieldIds
     *
     * @return array<string, string>
     */
    public function fetchFieldSetIds(array $customFieldIds): array
    {
        if ($customFieldIds === []) {
            return [];
        }

        /** @var array<string, string> $result */
        $result = $this->connection->fetchAllKeyValue(
            'SELECT ' . IdBinding::text('id') . ', ' . IdBinding::text('set_id') . ' FROM custom_field WHERE id IN (:ids) AND set_id IS NOT NULL',
            ['ids' => IdBinding::values($customFieldIds)],
            ['ids' => ArrayParameterType::STRING]
        );

        return $result;
    }

    /**
     * @param array<string> $fieldSetIds
     *
     * @return array<string, list<string>>
     */
    public function fetchFieldSetEntityMappings(array $fieldSetIds): array
    {
        if ($fieldSetIds === []) {
            return [];
        }

        /** @var list<array{set_id: string, entity_name: string}> $fieldSets */
        $fieldSets = $this->connection->fetchAllAssociative(
            '    SELECT ' . IdBinding::text('custom_field_set.id') . ' AS set_id, entity_name
    FROM custom_field_set
    LEFT JOIN custom_field_set_relation ON custom_field_set.id = custom_field_set_relation.set_id
    WHERE custom_field_set.id IN (:ids)',
            ['ids' => IdBinding::values($fieldSetIds)],
            ['ids' => ArrayParameterType::STRING]
        );

        return FetchModeHelper::group($fieldSets, static fn (array $row): string => (string) $row['entity_name']);
    }

    /**
     * @return array<string>
     */
    public function fetchLanguageIds(): array
    {
        /** @var list<string> $languageIds */
        $languageIds = $this->connection->fetchFirstColumn('SELECT ' . IdBinding::text('`id`') . ' FROM language');

        return $languageIds;
    }
}

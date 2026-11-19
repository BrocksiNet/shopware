<?php declare(strict_types=1);

namespace Shopware\Core\Content\Category\DataAbstractionLayer;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Adapter\Database\Dialect\IdBinding;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Doctrine\RetryableQuery;
use Shopware\Core\Framework\Log\Package;

#[Package('discovery')]
class CategoryBreadcrumbUpdater
{
    private const WRITE_CHUNK_SIZE = 250;

    /**
     * @internal
     */
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * @param string[] $ids
     */
    public function update(array $ids, Context $context): void
    {
        if ($ids === []) {
            return;
        }

        $all = $this->collectCategoryIds($ids, $context);

        foreach ($this->fetchActiveLanguages() as $language) {
            $languageChain = array_values(array_unique(array_filter([
                $language['id'],
                $language['parentId'],
                Defaults::LANGUAGE_SYSTEM,
            ])));

            $names = $this->fetchNames($all, $languageChain);

            $this->updateLanguage($ids, $language['id'], $names);
        }
    }

    /**
     * @return list<array{id: string, parentId: string|null}>
     */
    private function fetchActiveLanguages(): array
    {
        /** @var list<array{id: string, parentId: string|null}> $languages */
        $languages = $this->connection->fetchAllAssociative(
            'SELECT ' . IdBinding::text('`id`') . ' AS id, ' . IdBinding::text('`parent_id`') . ' AS parentId FROM `language` WHERE `active` = 1'
        );

        return $languages;
    }

    /**
     * @param string[] $ids
     *
     * @return string[]
     */
    private function collectCategoryIds(array $ids, Context $context): array
    {
        $query = $this->connection->createQueryBuilder();
        $query->select('category.path');
        $query->from('category');
        $query->where('category.id IN (:ids)');
        $query->andWhere('category.version_id = :version');
        $query->setParameter('version', IdBinding::value($context->getVersionId()));
        $query->setParameter('ids', IdBinding::values($ids), IdBinding::listType());

        $paths = $query->executeQuery()->fetchFirstColumn();

        $all = $ids;
        foreach ($paths as $path) {
            foreach (explode('|', (string) $path) as $id) {
                $all[] = $id;
            }
        }

        return array_filter(array_keys(array_flip($all)));
    }

    /**
     * @param string[] $ids
     * @param string[] $languageChain
     *
     * @return array<string, array{parentId: string|null, name: string|null}>
     */
    private function fetchNames(array $ids, array $languageChain): array
    {
        $query = $this->connection->createQueryBuilder();
        $query->from('category');
        $query->where('category.id IN (:ids)');
        $query->andWhere('category.version_id = :version');
        $query->setParameter('version', IdBinding::value(Defaults::LIVE_VERSION));
        $query->setParameter('ids', IdBinding::values($ids), IdBinding::listType());

        $coalesce = [];
        foreach ($languageChain as $index => $languageId) {
            $alias = 'translation' . $index;
            $query->leftJoin(
                'category',
                'category_translation',
                $alias,
                \sprintf(
                    '%1$s.category_id = category.id'
                    . ' AND %1$s.category_version_id = category.version_id'
                    . ' AND %1$s.language_id = :language%2$d',
                    $alias,
                    $index
                )
            );
            $query->setParameter('language' . $index, IdBinding::value($languageId));
            $coalesce[] = $alias . '.name';
        }

        $query->select(
            IdBinding::text('category.id') . ' AS id',
            IdBinding::text('category.parent_id') . ' AS parentId',
            'COALESCE(' . implode(', ', $coalesce) . ') AS name'
        );

        $names = [];
        foreach ($query->executeQuery()->fetchAllAssociative() as $row) {
            $names[(string) $row['id']] = [
                'parentId' => $row['parentId'] !== null ? (string) $row['parentId'] : null,
                'name' => $row['name'] !== null ? (string) $row['name'] : null,
            ];
        }

        return $names;
    }

    /**
     * @param string[] $ids
     * @param array<string, array{parentId: string|null, name: string|null}> $names
     */
    private function updateLanguage(array $ids, string $languageId, array $names): void
    {
        $versionId = IdBinding::value(Defaults::LIVE_VERSION);
        $languageIdBytes = IdBinding::value($languageId);

        $cache = [];
        $rows = [];
        foreach ($ids as $id) {
            $breadcrumb = $this->buildBreadcrumb($id, $names, $cache);
            if ($breadcrumb === null) {
                continue;
            }

            $rows[] = [
                IdBinding::value($id),
                $versionId,
                $languageIdBytes,
                json_encode($breadcrumb, \JSON_THROW_ON_ERROR),
            ];
        }

        foreach (array_chunk($rows, self::WRITE_CHUNK_SIZE) as $chunk) {
            $this->write($chunk);
        }
    }

    /**
     * @param array<string, array{parentId: string|null, name: string|null}> $names
     * @param array<string, array<string, string|null>|null> $cache
     *
     * @return array<string, string|null>|null
     */
    private function buildBreadcrumb(string $id, array $names, array &$cache): ?array
    {
        if (\array_key_exists($id, $cache)) {
            return $cache[$id];
        }

        $category = $names[$id] ?? null;
        if ($category === null) {
            return $cache[$id] = null;
        }

        $breadcrumb = [];
        if ($category['parentId'] !== null) {
            $parent = $this->buildBreadcrumb($category['parentId'], $names, $cache);
            if ($parent === null) {
                // A missing ancestor invalidates the whole chain, as in core.
                return $cache[$id] = null;
            }
            $breadcrumb = $parent;
        }

        $breadcrumb[$id] = $category['name'];

        return $cache[$id] = $breadcrumb;
    }

    /**
     * @param array<int, array{0: string, 1: string, 2: string, 3: string}> $rows
     */
    private function write(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $placeholders = implode(', ', array_fill(0, \count($rows), '(?, ?, ?, ?, DATE(NOW()))'));

        $parameters = [];
        foreach ($rows as $row) {
            foreach ($row as $value) {
                $parameters[] = $value;
            }
        }

        RetryableQuery::retryable($this->connection, function () use ($placeholders, $parameters): void {
            $this->connection->executeStatement(
                'INSERT INTO `category_translation` (`category_id`, `category_version_id`, `language_id`, `breadcrumb`, `created_at`)
                 VALUES ' . $placeholders . '
                 ON DUPLICATE KEY UPDATE `breadcrumb` = VALUES(`breadcrumb`)',
                $parameters
            );
        });
    }
}

<?php declare(strict_types=1);

namespace Shopware\Core\Migration\V6_3;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\DataAbstractionLayer\Util\StatementHelper;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * @internal
 */
#[Package('framework')]
class Migration1587039363AddImportExportLabelField extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1587039363;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement('ALTER TABLE `import_export_profile` MODIFY `name` VARCHAR(255) NULL;');

        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `import_export_profile_translation` (
                `import_export_profile_id` BINARY(16) NOT NULL,
                `language_id` BINARY(16) NOT NULL,
                `label` VARCHAR(255) NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`import_export_profile_id`, `language_id`),
                CONSTRAINT `fk.import_export_profile_translation.import_export_profile_id` FOREIGN KEY (`import_export_profile_id`)
                    REFERENCES `import_export_profile` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT `fk.import_export_profile_translation.language_id` FOREIGN KEY (`language_id`)
                    REFERENCES `language` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ');

        $translationsPresent = $connection->fetchOne('SELECT 1 FROM `import_export_profile_translation` LIMIT 1;');

        if ($translationsPresent !== false) {
            return;
        }

        $defaultLanguageId = Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM);
        $englishLanguageId = $connection->fetchOne('
            SELECT lang.id
            FROM language lang
            INNER JOIN locale loc ON lang.translation_code_id = loc.id
            AND loc.code = \'en-GB\';
        ');
        $germanLanguageId = $connection->fetchOne('
            SELECT lang.id
            FROM language lang
            INNER JOIN locale loc ON lang.translation_code_id = loc.id
            AND loc.code = \'de-DE\';
        ');

        $insertNamesAsLabelsStatement = $connection->prepare('
            INSERT INTO `import_export_profile_translation` (`import_export_profile_id`, `language_id`, `label`, `created_at`)
            SELECT `id`, :languageId, `name`, NOW()
            FROM `import_export_profile`;
        ');

        $germanLabels = [
            'Default category' => 'Standardprofil Kategorie',
            'Default media' => 'Standardprofil Medien',
            'Default variant configuration settings' => 'Standardprofil Variantenkonfiguration',
            'Default newsletter recipient' => 'Standardprofil Newsletter-Empfänger',
            'Default properties' => 'Standardprofil Eigenschaften',
            'Default product' => 'Standardprofil Produkt',
        ];

        if (!\in_array($defaultLanguageId, [$englishLanguageId, $germanLanguageId], true)) {
            StatementHelper::executeStatement($insertNamesAsLabelsStatement, [
                'languageId' => $defaultLanguageId,
            ]);
        }

        if ($englishLanguageId) {
            StatementHelper::executeStatement($insertNamesAsLabelsStatement, [
                'languageId' => $englishLanguageId,
            ]);
        }

        if ($germanLanguageId) {
            $profiles = $connection->fetchAllKeyValue('SELECT `id`, `name` FROM `import_export_profile`');
            foreach ($profiles as $id => $name) {
                $connection->insert('import_export_profile_translation', [
                    'import_export_profile_id' => $id,
                    'language_id' => $germanLanguageId,
                    'label' => $germanLabels[$name] ?? $name,
                    'created_at' => (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
                ]);
            }
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}

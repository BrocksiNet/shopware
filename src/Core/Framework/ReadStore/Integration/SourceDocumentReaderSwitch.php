<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Integration;

use Shopware\Core\Framework\Log\Package;

/**
 * Wave 10 A: bench switch for the O1a `_source` reader in `src/Elasticsearch`, so core bench code does not depend on the bundle.
 *
 * @internal proof of concept
 */
#[Package('framework')]
interface SourceDocumentReaderSwitch
{
    public function setRuntimeEnabled(bool $enabled): void;

    /**
     * @return array<string, int>
     */
    public function stats(): array;

    public function resetStats(): void;
}

<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Probe;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\ReadStore\Builder\DocumentExtender;

/**
 * Handoff C step 4 extension probe: simulates a plugin that projects an EntityExtension-style attribute
 * (`extensions.samplePlugin.badge`) onto every product document when `READ_STORE_SAMPLE_EXTENDER=1` is set.
 *
 * @internal
 */
#[Package('framework')]
final class SamplePluginExtender implements DocumentExtender
{
    public function name(): string
    {
        return 'samplePlugin';
    }

    public function extend(array $documents): array
    {
        $enabled = $_SERVER['READ_STORE_SAMPLE_EXTENDER'] ?? $_ENV['READ_STORE_SAMPLE_EXTENDER'] ?? getenv('READ_STORE_SAMPLE_EXTENDER');
        if (!\in_array(strtolower((string) $enabled), ['1', 'true', 'yes'], true)) {
            return [];
        }

        $result = [];
        foreach ($documents as $id => $document) {
            $stock = (int) ($document['stock'] ?? 0);
            $result[$id] = [
                'badge' => $stock >= 50 ? 'in-stock-plus' : 'standard',
                'tier' => $stock >= 50 ? 'gold' : 'silver',
            ];
        }

        return $result;
    }
}

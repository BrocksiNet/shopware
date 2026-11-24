<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Builder;

use Shopware\Core\Framework\Log\Package;

/**
 * Applies all tagged {@see DocumentExtender}s to a batch of documents.
 *
 * @internal
 */
#[Package('framework')]
final readonly class DocumentExtenders
{
    /**
     * @param iterable<DocumentExtender> $extenders
     */
    public function __construct(private iterable $extenders = [])
    {
    }

    /**
     * @param array<string, array<string, mixed>> $documents keyed by id
     *
     * @return array<string, array<string, mixed>>
     */
    public function apply(array $documents): array
    {
        if ($documents === []) {
            return $documents;
        }

        foreach ($this->extenders as $extender) {
            $name = $extender->name();
            foreach ($extender->extend($documents) as $id => $value) {
                if (isset($documents[$id])) {
                    $documents[$id]['extensions'][$name] = $value;
                }
            }
        }

        return $documents;
    }
}

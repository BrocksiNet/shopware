<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Port\Schema;

use Shopware\Core\Framework\Log\Package;

/**
 * A logical field resolved for a context: the document paths to read in order (the first non-missing value wins, `COALESCE`),
 * each a list of keys from the document root.
 *
 * @internal
 */
#[Package('framework')]
final readonly class ResolvedField
{
    /**
     * @param non-empty-list<list<string>> $paths
     */
    public function __construct(
        public string $field,
        public FieldType $type,
        public array $paths,
    ) {
    }

    public function isList(): bool
    {
        return $this->type === FieldType::LIST;
    }

    /**
     * @return list<string> dotted paths, for adapters whose engine addresses nested values with dots
     */
    public function dottedPaths(): array
    {
        return array_map(static fn (array $path): string => implode('.', $path), $this->paths);
    }
}

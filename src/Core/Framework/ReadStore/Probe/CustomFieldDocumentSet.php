<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Probe;

use Shopware\Core\Defaults;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Util\Hasher;

/**
 * Wave 10 handoff B (docs/48): turns a built product document into one with N custom fields and L languages, without writing to the
 * write database.
 *
 * - Custom fields: N product custom fields with the type mix of the store extensions (`docs/inventory/custom-fields/meta.json`,
 *   plugins and apps together) and names of 45 characters (median app custom field name length, same scan).
 *   A product fills one contiguous block of `round(fill * N)` fields (a product type fills whole sets), the block start depends on the id.
 * - Languages: every language-keyed map of the document (keys are language ids, the default language among them) is expanded to L languages,
 *   the extra ones copy the default language. That is what `SqlDocumentBuilder` does for a language without own translation: it resolves
 *   the fallback chain per language, so every language carries every value, custom fields included.
 *
 * @internal
 */
#[Package('framework')]
final class CustomFieldDocumentSet
{
    /**
     * Store plugins (static PHP scan) plus apps (manifest.xml) of the store mirror, field types folded into the custom field types of core.
     */
    public const TYPE_MIX = [
        'text' => 2129,
        'select' => 983,
        'bool' => 855,
        'media' => 339,
        'html' => 311,
        'float' => 242,
        'int' => 229,
        'datetime' => 141,
        'colorpicker' => 132,
        'entity' => 88,
        'json' => 39,
        'price' => 9,
    ];

    public const NAME_LENGTH = 45;

    private const VARIANTS = 8;

    private const LOREM = 'Lorem ipsum dolor sit amet consectetur adipiscing elit sed do eiusmod tempor incididunt ut labore et dolore magna aliqua Ut enim ad minim veniam quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur Excepteur sint occaecat cupidatat non proident sunt in culpa qui officia deserunt mollit anim id est laborum ';

    /**
     * @var list<array{name: string, type: string}>
     */
    private array $fields = [];

    /**
     * @var list<list<mixed>> per field, VARIANTS values
     */
    private array $values = [];

    /**
     * @var list<string>
     */
    private array $languageIds;

    /**
     * @param list<string> $baseLanguageIds languages of the built documents, the default language first
     */
    public function __construct(
        private readonly int $fieldCount,
        int $languageCount,
        private readonly float $fill,
        private readonly array $baseLanguageIds,
    ) {
        $this->languageIds = \array_slice($baseLanguageIds, 0, $languageCount);
        for ($i = \count($this->languageIds); $i < $languageCount; ++$i) {
            $this->languageIds[] = Hasher::hash('w10b-language-' . $i, 'md5');
        }

        $types = self::types($fieldCount);
        foreach ($types as $i => $type) {
            $set = intdiv($i, 10);
            $name = \sprintf('w10b_probe_set%04d_%s_field%05d_', $set, $type, $i);
            $name = str_pad($name, self::NAME_LENGTH, 'x');
            $this->fields[] = ['name' => $name, 'type' => $type];
            $variants = [];
            for ($v = 0; $v < self::VARIANTS; ++$v) {
                $variants[] = self::value($type, $i * self::VARIANTS + $v);
            }
            $this->values[] = $variants;
        }
    }

    /**
     * @return list<array{name: string, type: string}>
     */
    public function fields(): array
    {
        return $this->fields;
    }

    /**
     * @return list<string>
     */
    public function languageIds(): array
    {
        return $this->languageIds;
    }

    public function filledPerProduct(): int
    {
        return (int) round($this->fill * $this->fieldCount);
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    public function augment(array $document, bool $allFields = false): array
    {
        $document = $this->expandLanguages($document);

        $values = $this->customFieldValues((string) $document['id'], $allFields);
        $customFields = [];
        foreach ($this->languageIds as $languageId) {
            $own = $document['customFields'][$languageId] ?? [];
            $customFields[$languageId] = (\is_array($own) ? $own : []) + $values;
        }
        $document['customFields'] = $customFields;

        return $document;
    }

    /**
     * @return array<string, mixed>
     */
    public function customFieldValues(string $productId, bool $allFields = false): array
    {
        if ($this->fieldCount === 0) {
            return [];
        }
        $count = $allFields ? $this->fieldCount : $this->filledPerProduct();
        $seed = crc32($productId);
        $start = $seed % $this->fieldCount;
        $values = [];
        for ($j = 0; $j < $count; ++$j) {
            $i = ($start + $j) % $this->fieldCount;
            $values[$this->fields[$i]['name']] = $this->values[$i][($seed + $j) % self::VARIANTS];
        }

        return $values;
    }

    /**
     * Copies the default language entry of every language-keyed map to the languages of this set (and drops the base languages beyond it).
     */
    public function expandLanguages(mixed $value): mixed
    {
        if (!\is_array($value) || $value === [] || array_is_list($value)) {
            return \is_array($value) ? array_map($this->expandLanguages(...), $value) : $value;
        }

        if ($this->isLanguageMap($value)) {
            $default = $value[Defaults::LANGUAGE_SYSTEM];
            $expanded = [];
            foreach ($this->languageIds as $languageId) {
                $expanded[$languageId] = \array_key_exists($languageId, $value) ? $value[$languageId] : $default;
            }

            return $expanded;
        }

        foreach ($value as $key => $child) {
            if (\is_array($child)) {
                $value[$key] = $this->expandLanguages($child);
            }
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private function isLanguageMap(array $value): bool
    {
        if (!\array_key_exists(Defaults::LANGUAGE_SYSTEM, $value)) {
            return false;
        }
        foreach (array_keys($value) as $key) {
            if (!\in_array((string) $key, $this->baseLanguageIds, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Spreads the types over the fields in the proportions of {@see self::TYPE_MIX} (largest share first, interleaved).
     *
     * @return list<string>
     */
    private static function types(int $count): array
    {
        $total = array_sum(self::TYPE_MIX);
        $assigned = array_fill_keys(array_keys(self::TYPE_MIX), 0);
        $types = [];
        for ($i = 0; $i < $count; ++$i) {
            $best = null;
            $bestGap = -\INF;
            foreach (self::TYPE_MIX as $type => $weight) {
                $gap = ($i + 1) * $weight / $total - $assigned[$type];
                if ($gap > $bestGap) {
                    $bestGap = $gap;
                    $best = $type;
                }
            }
            \assert($best !== null);
            ++$assigned[$best];
            $types[] = $best;
        }

        return $types;
    }

    /**
     * Value sizes are assumptions (docs/48): short text 10 to 50 characters, html 200 to 600, ids as 32 hex characters.
     */
    private static function value(string $type, int $n): mixed
    {
        $hash = crc32('w10b-value-' . $n);

        return match ($type) {
            'text' => trim(substr(self::LOREM, $hash % 200, 10 + $hash % 41)),
            'html' => '<p>' . substr(str_repeat(self::LOREM, 2), $hash % 200, 200 + $hash % 401) . '</p>',
            'select' => 'option_' . ($hash % 50),
            'bool' => ($hash & 1) === 1,
            'int' => $hash % 100000,
            'float' => ($hash % 100000) / 100,
            'datetime' => \sprintf('2026-%02d-%02dT10:00:00.000+00:00', 1 + $hash % 12, 1 + $hash % 28),
            'media', 'entity' => Hasher::hash('w10b-id-' . $n, 'md5'),
            'colorpicker' => '#' . substr(Hasher::hash('w10b-color-' . $n, 'md5'), 0, 6),
            'json' => ['code' => 'c' . ($hash % 1000), 'level' => $hash % 10],
            'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => ($hash % 10000) / 100, 'net' => ($hash % 8400) / 100, 'linked' => false]],
            default => null,
        };
    }
}

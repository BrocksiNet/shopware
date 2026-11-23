<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\Harness;

use Shopware\Core\Framework\Log\Package;

/**
 * @internal
 */
#[Package('framework')]
final class Verifier
{
    /**
     * Returns the path and both values of the first difference, or null if both results are equal.
     */
    public function diff(mixed $expected, mixed $actual, string $path = '$'): ?string
    {
        $expected = ResultContract::canonical($expected);
        $actual = ResultContract::canonical($actual);

        if (\is_array($expected) && \is_array($actual)) {
            foreach ($expected as $key => $value) {
                if (!\array_key_exists($key, $actual)) {
                    return \sprintf('%s.%s missing (expected %s)', $path, $key, $this->short($value));
                }
                $diff = $this->diff($value, $actual[$key], $path . '.' . $key);
                if ($diff !== null) {
                    return $diff;
                }
            }
            foreach ($actual as $key => $value) {
                if (!\array_key_exists($key, $expected)) {
                    return \sprintf('%s.%s unexpected (got %s)', $path, $key, $this->short($value));
                }
            }

            return null;
        }

        if ($expected === $actual) {
            return null;
        }

        return \sprintf('%s expected %s, got %s', $path, $this->short($expected), $this->short($actual));
    }

    private function short(mixed $value): string
    {
        return mb_substr((string) json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES), 0, 160);
    }
}

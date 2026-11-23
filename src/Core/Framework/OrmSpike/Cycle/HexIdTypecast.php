<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\Cycle;

use Cycle\ORM\Parser\CastableInterface;
use Cycle\ORM\Parser\TypecastInterface;
use Cycle\ORM\Parser\UncastableInterface;
use Shopware\Core\Framework\Adapter\Database\Dialect\IdBinding;
use Shopware\Core\Framework\Log\Package;

/**
 * Cycle typecast handler for the rule `hexid`: ids are 32 character hex strings in PHP, the database form (16 bytes or `uuid` text)
 * is decided by {@see IdBinding}, so the mapping itself does not know the database.
 * Used together with Cycle's own handler (`TYPECAST_HANDLER => [HexIdTypecast::class, Typecast::class]`).
 * The rule only runs for hydration and writes: values in `where()` do not pass through it (see docs/32).
 *
 * @internal
 */
#[Package('framework')]
final class HexIdTypecast implements TypecastInterface, CastableInterface, UncastableInterface
{
    public const RULE = 'hexid';

    /**
     * @var list<string>
     */
    private array $fields = [];

    public function setRules(array $rules): array
    {
        foreach ($rules as $field => $rule) {
            if ($rule === self::RULE) {
                $this->fields[] = $field;
                unset($rules[$field]);
            }
        }

        return $rules;
    }

    public function cast(array $values): array
    {
        foreach ($this->fields as $field) {
            if (isset($values[$field]) && \is_string($values[$field])) {
                $values[$field] = IdBinding::toHex($values[$field]);
            }
        }

        return $values;
    }

    public function uncast(array $values): array
    {
        foreach ($this->fields as $field) {
            if (isset($values[$field]) && \is_string($values[$field])) {
                $values[$field] = IdBinding::value($values[$field]);
            }
        }

        return $values;
    }
}

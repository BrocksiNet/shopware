<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\Fair\Constraint;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Validation\Constraint\Uuid;
use Shopware\Core\Framework\Validation\Constraint\UuidValidator;

/**
 * The `Uuid` constraint of the DAL (`FkFieldSerializer`, `IdFieldSerializer`) as an attribute, with the same validator.
 *
 * @internal
 */
#[Package('framework')]
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class HexUuid extends Uuid
{
    public function validatedBy(): string
    {
        return UuidValidator::class;
    }
}

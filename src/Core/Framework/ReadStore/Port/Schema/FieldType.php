<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Port\Schema;

use Shopware\Core\Framework\Log\Package;

/**
 * Value types of document fields. Taken from the prototype `shopwareArchive/storage` (`string`, `text`, `int`, `float`, `bool`, `datetime`,
 * `list`, `object`, `object_list`) with `id` (exact, lower case hex) split from `string` (case-insensitive) and `any` added for
 * custom fields and plugin extensions, whose type the core schema does not know.
 *
 * @internal
 */
#[Package('framework')]
enum FieldType: string
{
    case ID = 'id';
    case STRING = 'string';
    case TEXT = 'text';
    case INT = 'int';
    case FLOAT = 'float';
    case BOOL = 'bool';
    case DATETIME = 'datetime';
    case LIST = 'list';
    case OBJECT = 'object';
    case OBJECT_LIST = 'object_list';
    case ANY = 'any';

    public function isCaseInsensitive(): bool
    {
        return $this === self::STRING || $this === self::TEXT || $this === self::ANY;
    }
}

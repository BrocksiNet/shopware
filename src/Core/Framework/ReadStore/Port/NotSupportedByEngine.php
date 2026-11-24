<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Port;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\ReadStore\ReadStoreException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The engine cannot answer the query (field, filter, aggregation, sorting or option). The caller falls back to the DAL for the whole query.
 *
 * @internal
 */
#[Package('framework')]
class NotSupportedByEngine extends ReadStoreException
{
    public const NOT_SUPPORTED = 'FRAMEWORK__READ_STORE_NOT_SUPPORTED';

    public static function feature(string $engine, string $feature): self
    {
        return new self(Response::HTTP_BAD_REQUEST, self::NOT_SUPPORTED, 'Read store "{{ engine }}" does not support {{ feature }}', ['engine' => $engine, 'feature' => $feature]);
    }

    public static function field(string $field, string $reason): self
    {
        return new self(Response::HTTP_BAD_REQUEST, self::NOT_SUPPORTED, 'Read store field "{{ field }}" cannot be resolved: {{ reason }}', ['field' => $field, 'reason' => $reason]);
    }
}

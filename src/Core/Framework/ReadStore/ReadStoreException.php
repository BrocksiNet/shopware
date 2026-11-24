<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore;

use Shopware\Core\Framework\HttpException;
use Shopware\Core\Framework\Log\Package;
use Symfony\Component\HttpFoundation\Response;

/**
 * @internal
 */
#[Package('framework')]
class ReadStoreException extends HttpException
{
    public const UNKNOWN_GENERATION = 'FRAMEWORK__READ_STORE_UNKNOWN_GENERATION';
    public const NO_LIVE_GENERATION = 'FRAMEWORK__READ_STORE_NO_LIVE_GENERATION';
    public const INVALID_CRITERIA = 'FRAMEWORK__READ_STORE_INVALID_CRITERIA';
    public const NOT_REGISTERED = 'FRAMEWORK__READ_STORE_NOT_REGISTERED';
    public const ENGINE_FAILURE = 'FRAMEWORK__READ_STORE_ENGINE_FAILURE';

    public static function unknownGeneration(string $generation): self
    {
        return new self(Response::HTTP_INTERNAL_SERVER_ERROR, self::UNKNOWN_GENERATION, 'Unknown read store generation "{{ generation }}"', ['generation' => $generation]);
    }

    public static function noLiveGeneration(): self
    {
        return new self(Response::HTTP_INTERNAL_SERVER_ERROR, self::NO_LIVE_GENERATION, 'The read store has no live generation, call setup() and swap() first');
    }

    public static function invalidCriteria(string $reason): self
    {
        return new self(Response::HTTP_BAD_REQUEST, self::INVALID_CRITERIA, 'Invalid read store criteria: {{ reason }}', ['reason' => $reason]);
    }

    /**
     * @param list<string> $registered
     */
    public static function notRegistered(string $type, string $key, array $registered): self
    {
        return new self(Response::HTTP_INTERNAL_SERVER_ERROR, self::NOT_REGISTERED, '{{ type }} "{{ key }}" is not registered (registered: {{ registered }})', ['type' => $type, 'key' => $key, 'registered' => implode(', ', $registered)]);
    }

    /**
     * The engine failed (connection, timeout, server error); a caller that can fall back to the DAL catches this, unlike {@see Port\NotSupportedByEngine}
     * it is not a property of the query.
     */
    public static function engineFailure(string $engine, \Throwable $previous): self
    {
        return new self(Response::HTTP_SERVICE_UNAVAILABLE, self::ENGINE_FAILURE, 'Read store "{{ engine }}" failed: {{ message }}', ['engine' => $engine, 'message' => $previous->getMessage()], $previous);
    }
}

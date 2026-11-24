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
}

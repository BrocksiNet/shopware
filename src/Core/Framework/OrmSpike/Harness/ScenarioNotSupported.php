<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\Harness;

use Shopware\Core\Framework\Log\Package;

/**
 * Thrown by an implementation for a scenario it cannot run (the bench records it as `unsupported` with the message).
 *
 * @internal
 */
#[Package('framework')]
final class ScenarioNotSupported extends \RuntimeException
{
}

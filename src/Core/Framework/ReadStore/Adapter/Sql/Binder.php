<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ReadStore\Adapter\Sql;

use Doctrine\DBAL\ParameterType;
use Shopware\Core\Framework\Log\Package;

/**
 * Collects named parameters while a statement is assembled. Every value gets its own placeholder, so no driver ever
 * sees a placeholder twice (pdo_mysql with native prepares does not allow that).
 *
 * @internal
 */
#[Package('framework')]
final class Binder
{
    /**
     * @var array<string, mixed>
     */
    private array $params = [];

    /**
     * @var array<string, ParameterType>
     */
    private array $types = [];

    private int $counter = 0;

    public function bind(string|int|float $value): string
    {
        $name = 'p' . $this->counter++;
        $this->params[$name] = \is_float($value) ? (string) $value : $value;
        $this->types[$name] = \is_int($value) ? ParameterType::INTEGER : ParameterType::STRING;

        return ':' . $name;
    }

    /**
     * @return array<string, mixed>
     */
    public function params(): array
    {
        return $this->params;
    }

    /**
     * @return array<string, ParameterType>
     */
    public function types(): array
    {
        return $this->types;
    }
}

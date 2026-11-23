<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\DalV2\Projection;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\AggregationResultCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Lever R3: `project(ProductListItem::class, $criteria, $context)` returns readonly DTOs.
 *
 * The constructor parameters of the DTO carry {@see Path} attributes. The set of paths is the partial loading field list
 * (`Criteria::addFields()`), so only these columns are selected and only the needed associations are joined.
 * The DAL reads partial entities, the projector copies the named paths into the DTO constructor and drops the entity objects.
 *
 * @internal
 */
#[Package('framework')]
class Projector
{
    /**
     * @var array<class-string, list<array{name: string, path: string, fields: list<string>}>>
     */
    private static array $meta = [];

    public function __construct(private readonly SalesChannelRepository $repository)
    {
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $dto
     *
     * @return array{total: int, items: list<T>, aggregations: AggregationResultCollection}
     */
    public function project(string $dto, Criteria $criteria, SalesChannelContext $context): array
    {
        $meta = self::meta($dto);
        $fields = [];
        foreach ($meta as $parameter) {
            foreach ($parameter['fields'] === [] ? [$parameter['path']] : array_map(static fn (string $f): string => $parameter['path'] . '.' . $f, $parameter['fields']) as $field) {
                $fields[] = $field;
            }
        }
        $criteria->addFields($fields);

        $result = $this->repository->search($criteria, $context);

        $items = [];
        foreach ($result->getEntities() as $entity) {
            $args = [];
            foreach ($meta as $parameter) {
                $args[$parameter['name']] = self::get($entity, $parameter['path']);
            }
            $items[] = new $dto(...$args);
        }

        return ['total' => $result->getTotal(), 'items' => $items, 'aggregations' => $result->getAggregations()];
    }

    public static function get(mixed $entity, string $path): mixed
    {
        foreach (explode('.', $path) as $segment) {
            if ($entity === null) {
                return null;
            }
            if ($entity instanceof Entity) {
                $translated = $entity->getTranslated();
                $entity = \array_key_exists($segment, $translated) && $translated[$segment] !== null ? $translated[$segment] : $entity->get($segment);

                continue;
            }
            $entity = $entity->get($segment);
        }

        return $entity;
    }

    /**
     * @return list<array{name: string, path: string, fields: list<string>}>
     */
    private static function meta(string $dto): array
    {
        if (isset(self::$meta[$dto])) {
            return self::$meta[$dto];
        }

        $constructor = (new \ReflectionClass($dto))->getConstructor();
        $meta = [];
        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            $attributes = $parameter->getAttributes(Path::class);
            if ($attributes === []) {
                throw new \LogicException(\sprintf('%s::$%s has no #[Path]', $dto, $parameter->getName()));
            }
            $path = $attributes[0]->newInstance();
            $meta[] = ['name' => $parameter->getName(), 'path' => $path->path, 'fields' => $path->fields];
        }

        return self::$meta[$dto] = $meta;
    }
}

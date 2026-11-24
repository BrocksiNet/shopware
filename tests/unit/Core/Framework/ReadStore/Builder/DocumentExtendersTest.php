<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Framework\ReadStore\Builder;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\ReadStore\Adapter\ArrayStore\ArrayStorage;
use Shopware\Core\Framework\ReadStore\Builder\DocumentExtender;
use Shopware\Core\Framework\ReadStore\Builder\DocumentExtenders;
use Shopware\Core\Framework\ReadStore\Port\Criteria\Filter\Equals;
use Shopware\Core\Framework\ReadStore\Port\Criteria\StoreCriteria;
use Shopware\Tests\Unit\Core\Framework\ReadStore\Contract\ContractFixtures as F;

/**
 * @internal
 */
#[CoversClass(DocumentExtenders::class)]
class DocumentExtendersTest extends TestCase
{
    public function testExtenderAddsQueryableExtensionFieldToDocuments(): void
    {
        $extender = new class implements DocumentExtender {
            public function name(): string
            {
                return 'demo_badge';
            }

            public function extend(array $documents): array
            {
                $out = [];
                foreach ($documents as $id => $document) {
                    if ($document['productNumber'] === 'SW-S1') {
                        $out[$id] = ['label' => 'bestseller'];
                    }
                }

                return $out;
            }
        };

        $byId = [];
        foreach (F::documents() as $document) {
            $byId[(string) $document['id']] = $document;
        }
        $extended = (new DocumentExtenders([$extender]))->apply($byId);

        static::assertSame(['label' => 'bestseller'], $extended[F::S1]['extensions']['demo_badge']);
        static::assertArrayNotHasKey('demo_badge', $extended[F::S2]['extensions']);

        $storage = new ArrayStorage();
        $storage->setup('g1');
        $storage->swap('g1');
        $storage->store(array_values($extended));

        $result = $storage->filter(new StoreCriteria(filters: [new Equals('extensions.demo_badge.label', 'bestseller')]), F::context());
        static::assertSame([F::S1], $result->ids());
    }
}

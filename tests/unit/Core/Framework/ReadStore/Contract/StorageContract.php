<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Framework\ReadStore\Contract;

use Shopware\Tests\Unit\Core\Framework\ReadStore\Contract\ContractFixtures as F;

/**
 * Contract of {@see \Shopware\Core\Framework\ReadStore\Port\Storage}: documents round-trip unchanged, `mget` keeps the order, writes reach
 * the live and the pending generation, a swap switches readers to the new generation.
 *
 * @internal
 *
 * @phpstan-require-extends ContractTestCase
 */
trait StorageContract
{
    public function testGetReturnsTheStoredDocument(): void
    {
        $expected = array_column(F::documents(), null, 'id');

        static::assertEquals($expected[F::S1], $this->storage->get(F::S1));
        static::assertEquals($expected[F::V2], $this->storage->get(F::V2));
    }

    public function testGetUnknownIdReturnsNull(): void
    {
        static::assertNull($this->storage->get('ffffffffffffffffffffffffffffffff'));
    }

    public function testMgetKeepsTheRequestedOrderAndSkipsUnknownIds(): void
    {
        $documents = $this->storage->mget([F::V3, 'ffffffffffffffffffffffffffffffff', F::S1, F::P]);

        static::assertSame([F::V3, F::S1, F::P], array_keys($documents));
        static::assertSame(F::S1, $documents[F::S1]['id']);
        static::assertSame([], $this->storage->mget([]));
    }

    public function testStoreReplacesADocument(): void
    {
        $document = $this->storage->get(F::S1);
        static::assertNotNull($document);
        $document['stock'] = 99;
        $document['name'][F::DE] = 'Neuer Name';

        $this->storage->store([$document]);

        $stored = $this->storage->get(F::S1);
        static::assertNotNull($stored);
        static::assertSame(99, $stored['stock']);
        static::assertSame('Neuer Name', $stored['name'][F::DE]);
        static::assertCount(\count(F::ALL), $this->storage->mget(F::ALL));
    }

    public function testRemoveDeletesDocuments(): void
    {
        $this->storage->remove([F::S1, F::V2, 'ffffffffffffffffffffffffffffffff']);

        static::assertNull($this->storage->get(F::S1));
        static::assertNull($this->storage->get(F::V2));
        static::assertNotNull($this->storage->get(F::S2));
    }

    public function testClearEmptiesTheLiveGeneration(): void
    {
        $this->storage->clear();

        static::assertSame([], $this->storage->mget(F::ALL));
        static::assertSame('g1', $this->storage->liveGeneration());
    }

    public function testSwapSwitchesToTheNewGeneration(): void
    {
        $documents = array_column(F::documents(), null, 'id');
        static::assertSame('g1', $this->storage->liveGeneration());

        $this->storage->setup('g2');
        $this->storage->store([$documents[F::S1], $documents[F::S2]], 'g2');

        // readers stay on the live generation until the swap
        static::assertSame('g1', $this->storage->liveGeneration());
        static::assertCount(\count(F::ALL), $this->storage->mget(F::ALL));

        $this->storage->swap('g2');

        static::assertSame('g2', $this->storage->liveGeneration());
        static::assertSame([F::S1, F::S2], array_keys($this->storage->mget(F::ALL)));
    }

    public function testWritesDuringARebuildReachTheLiveAndThePendingGeneration(): void
    {
        $documents = array_column(F::documents(), null, 'id');
        $this->storage->setup('g2');
        $this->storage->store([$documents[F::S1], $documents[F::S2]], 'g2');

        // a projector write while the rebuild runs
        $changed = $documents[F::S2];
        $changed['stock'] = 42;
        $this->storage->store([$changed]);
        $this->storage->remove([F::S1]);

        static::assertSame(42, $this->storage->get(F::S2)['stock'] ?? null);
        static::assertNull($this->storage->get(F::S1));

        $this->storage->swap('g2');

        static::assertSame(42, $this->storage->get(F::S2)['stock'] ?? null);
        static::assertNull($this->storage->get(F::S1));
    }
}

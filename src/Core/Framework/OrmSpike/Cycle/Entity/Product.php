<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\Cycle\Entity;

use Shopware\Core\Framework\Log\Package;

/**
 * @internal
 */
#[Package('framework')]
class Product
{
    public string $id;

    public string $versionId;

    public ?string $parentId = null;

    public ?string $parentVersionId = null;

    public ?string $productNumber = null;

    public ?int $active = null;

    public ?int $stock = null;

    public ?string $price = null;

    public ?string $manufacturerId = null;

    public ?string $manufacturerVersionId = null;

    public ?string $mediaId = null;

    public ?string $mediaVersionId = null;

    public ?string $taxId = null;

    public ?string $createdAt = null;

    public ?string $updatedAt = null;

    public array $translations = [];

    public array $translationsAlt = [];

    public ?Product $parent = null;

    public ?Manufacturer $manufacturer = null;

    public ?ProductMedia $cover = null;

    public array $prices = [];

    public array $categories = [];

    public array $categoryTree = [];

    public array $visibilities = [];

    public array $options = [];
}

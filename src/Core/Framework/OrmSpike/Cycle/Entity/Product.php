<?php declare(strict_types=1);

namespace Shopware\Core\Framework\OrmSpike\Cycle\Entity;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\OrmSpike\Fair\Constraint\HexUuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @internal
 */
#[Package('framework')]
class Product
{
    #[Assert\NotBlank]
    #[HexUuid]
    public string $id;

    public string $versionId;

    public ?string $parentId = null;

    public ?string $parentVersionId = null;

    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: 255, normalizer: 'trim')]
    public ?string $productNumber = null;

    public ?int $active = null;

    #[Assert\NotNull]
    public ?int $stock = null;

    public ?string $price = null;

    public ?string $manufacturerId = null;

    public ?string $manufacturerVersionId = null;

    public ?string $mediaId = null;

    public ?string $mediaVersionId = null;

    #[Assert\NotBlank]
    #[HexUuid]
    public ?string $taxId = null;

    public ?string $createdAt = null;

    public ?string $updatedAt = null;

    #[Assert\Valid]
    public array $translations = [];

    public array $translationsAlt = [];

    public ?Product $parent = null;

    public ?Manufacturer $manufacturer = null;

    public ?ProductMedia $cover = null;

    public array $prices = [];

    #[Assert\Valid]
    public array $categories = [];

    public array $categoryTree = [];

    #[Assert\Valid]
    public array $visibilities = [];

    public array $options = [];

    /**
     * The price column holds JSON, the validator needs the decoded list (only used by the write validation).
     *
     * @return array<string, mixed>
     */
    #[Assert\NotBlank]
    #[Assert\All([new Assert\Collection(fields: [
        'currencyId' => [new Assert\NotBlank(), new HexUuid()],
        'gross' => [new Assert\NotBlank(), new Assert\Type('numeric')],
        'net' => [new Assert\NotBlank(), new Assert\Type('numeric')],
        'linked' => [new Assert\Type('bool')],
    ])])]
    public function getPriceData(): array
    {
        return $this->price === null ? [] : json_decode($this->price, true, 512, \JSON_THROW_ON_ERROR);
    }
}

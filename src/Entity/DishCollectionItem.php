<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DishCollectionItemRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DishCollectionItemRepository::class)]
#[ORM\Table(name: 'dish_collection_item')]
#[ORM\UniqueConstraint(name: 'uniq_collection_dish', columns: ['collection_id', 'dish_id'])]
#[ORM\Index(columns: ['dish_id'], name: 'idx_collection_item_dish')]
class DishCollectionItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private DishCollection $collection,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Dish $dish,
    ) {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCollection(): DishCollection
    {
        return $this->collection;
    }

    public function getDish(): Dish
    {
        return $this->dish;
    }
}

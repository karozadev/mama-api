<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DishCollectionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A named list of dishes curated by a mother ("Classiques malgaches").
 */
#[ORM\Entity(repositoryClass: DishCollectionRepository::class)]
#[ORM\Table(name: 'dish_collection')]
#[ORM\Index(columns: ['owner_id', 'created_at'], name: 'idx_collection_owner_created')]
class DishCollection
{
    public const MAX_PER_USER = 50;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $owner,
        #[ORM\Column(length: 80)]
        private string $name,
        #[ORM\Column(options: ['default' => true])]
        private bool $public = true,
    ) {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function isPublic(): bool
    {
        return $this->public;
    }

    public function setPublic(bool $public): static
    {
        $this->public = $public;

        return $this;
    }
}

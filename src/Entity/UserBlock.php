<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserBlockRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UserBlockRepository::class)]
#[ORM\Table(name: 'user_block')]
#[ORM\UniqueConstraint(name: 'uniq_block_pair', columns: ['blocker_id', 'blocked_id'])]
#[ORM\Index(columns: ['blocked_id'], name: 'idx_block_blocked')]
class UserBlock
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
        private User $blocker,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $blocked,
    ) {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBlocker(): User
    {
        return $this->blocker;
    }

    public function getBlocked(): User
    {
        return $this->blocked;
    }
}

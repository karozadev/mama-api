<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ModerationActionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Audit trail of what moderators did (who, what, to what, why).
 */
#[ORM\Entity(repositoryClass: ModerationActionRepository::class)]
#[ORM\Table(name: 'moderation_action')]
#[ORM\Index(columns: ['target_type', 'target_id'], name: 'idx_moderation_target')]
class ModerationAction
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        private ?User $admin,
        #[ORM\Column(length: 30)]
        private string $action,
        #[ORM\Column(length: 20)]
        private string $targetType,
        #[ORM\Column]
        private int $targetId,
        #[ORM\Column(type: Types::TEXT, nullable: true)]
        private ?string $reason = null,
    ) {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAdmin(): ?User
    {
        return $this->admin;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getTargetType(): string
    {
        return $this->targetType;
    }

    public function getTargetId(): int
    {
        return $this->targetId;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}

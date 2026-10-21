<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DirectMessageRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DirectMessageRepository::class)]
#[ORM\Table(name: 'direct_message')]
#[ORM\Index(columns: ['sender_id', 'recipient_id', 'created_at'], name: 'idx_dm_pair_sender')]
#[ORM\Index(columns: ['recipient_id', 'sender_id', 'created_at'], name: 'idx_dm_pair_recipient')]
#[ORM\Index(columns: ['recipient_id', 'read_at'], name: 'idx_dm_unread')]
class DirectMessage
{
    public const MAX_LENGTH = 2000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $readAt = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $sender,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $recipient,
        #[ORM\Column(type: Types::TEXT)]
        private string $body,
    ) {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSender(): User
    {
        return $this->sender;
    }

    public function getRecipient(): User
    {
        return $this->recipient;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function isRead(): bool
    {
        return null !== $this->readAt;
    }

    public function setReadAt(?\DateTimeImmutable $readAt): static
    {
        $this->readAt = $readAt;

        return $this;
    }
}

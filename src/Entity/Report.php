<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ReportReason;
use App\Enum\ReportStatus;
use App\Enum\ReportTarget;
use App\Repository\ReportRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A user report about a mother, a dish, a review or a private message.
 */
#[ORM\Entity(repositoryClass: ReportRepository::class)]
#[ORM\Table(name: 'report')]
#[ORM\UniqueConstraint(name: 'uniq_report_once', columns: ['reporter_id', 'target_type', 'target_id'])]
#[ORM\Index(columns: ['status', 'created_at'], name: 'idx_report_status_created')]
#[ORM\Index(columns: ['reported_user_id', 'created_at'], name: 'idx_report_reported_created')]
class Report
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20, enumType: ReportStatus::class)]
    private ReportStatus $status = ReportStatus::Open;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    #[ORM\Column(length: 300, nullable: true)]
    private ?string $resolutionNote = null;

    /**
     * @param array<string, mixed>|null $context Snapshot taken at report time (e.g. the reported message and the messages before it)
     */
    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $reporter,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $reportedUser,
        #[ORM\Column(length: 20, enumType: ReportTarget::class)]
        private ReportTarget $targetType,
        #[ORM\Column]
        private int $targetId,
        #[ORM\Column(length: 20, enumType: ReportReason::class)]
        private ReportReason $reason,
        #[ORM\Column(type: Types::TEXT, nullable: true)]
        private ?string $details = null,
        #[ORM\Column(type: Types::JSON, nullable: true)]
        private ?array $context = null,
    ) {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReporter(): User
    {
        return $this->reporter;
    }

    public function getReportedUser(): User
    {
        return $this->reportedUser;
    }

    public function getTargetType(): ReportTarget
    {
        return $this->targetType;
    }

    public function getTargetId(): int
    {
        return $this->targetId;
    }

    public function getReason(): ReportReason
    {
        return $this->reason;
    }

    public function getDetails(): ?string
    {
        return $this->details;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getContext(): ?array
    {
        return $this->context;
    }

    public function getStatus(): ReportStatus
    {
        return $this->status;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getResolvedAt(): ?\DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    public function getResolutionNote(): ?string
    {
        return $this->resolutionNote;
    }

    public function resolve(ReportStatus $status, ?string $note = null): void
    {
        $this->status = $status;
        $this->resolvedAt = new \DateTimeImmutable();
        $this->resolutionNote = null === $note ? null : mb_substr($note, 0, 300);
    }
}

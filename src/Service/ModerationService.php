<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\DirectMessage;
use App\Entity\Dish;
use App\Entity\ModerationAction;
use App\Entity\Report;
use App\Entity\Review;
use App\Entity\User;
use App\Enum\ReportReason;
use App\Enum\ReportStatus;
use App\Enum\ReportTarget;
use App\Repository\ReviewRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Turns user reports into moderation signals: snapshots, automatic suspension, resolution.
 */
final readonly class ModerationService
{
    /** Distinct reporters within the window that trigger an automatic messaging suspension. */
    public const AUTO_SUSPEND_REPORTERS = 3;
    public const AUTO_SUSPEND_WINDOW = '-24 hours';
    public const AUTO_SUSPEND_DURATION = '+24 hours';
    public const MANUAL_SUSPEND_DURATION = '+7 days';

    public function __construct(
        private EntityManagerInterface $em,
        private Connection $db,
        private ReviewRepository $reviews,
    ) {
    }

    /**
     * Registers a report (idempotent per reporter and target). Returns null when it already existed.
     */
    public function report(User $reporter, User $reportedUser, ReportTarget $type, int $targetId, ReportReason $reason, ?string $details, ?DirectMessage $message = null): ?Report
    {
        $exists = $this->db->fetchOne(
            'SELECT 1 FROM report WHERE reporter_id = :r AND target_type = :t AND target_id = :id',
            ['r' => $reporter->getId(), 't' => $type->value, 'id' => $targetId],
        );
        if (false !== $exists) {
            return null;
        }

        $details = null === $details ? null : mb_substr(trim($details), 0, 500);
        $report = new Report($reporter, $reportedUser, $type, $targetId, $reason, '' === $details ? null : $details, null === $message ? null : $this->threadSnapshot($message));
        $this->em->persist($report);
        $this->em->flush();
        $this->autoSuspendIfNeeded($reportedUser);

        return $report;
    }

    /** @var list<string> */
    public const RESOLUTIONS = ['dismiss', 'suspend', 'hide', 'ban'];

    /**
     * Applies a moderator decision to a report:
     *  - dismiss: no action;
     *  - suspend: the reported mother cannot send messages for a week;
     *  - hide: hides the reported dish, or deletes the reported review;
     *  - ban: bans the reported mother (no login, content hidden).
     *
     * @throws \InvalidArgumentException when the action does not apply to this kind of report
     */
    public function resolve(Report $report, string $action, ?string $note = null, ?User $admin = null): void
    {
        if (!\in_array($action, self::RESOLUTIONS, true)) {
            throw new \InvalidArgumentException('Unknown action "'.$action.'".');
        }

        $user = $report->getReportedUser();
        $applied = ReportStatus::Actioned;
        switch ($action) {
            case 'dismiss':
                $applied = ReportStatus::Dismissed;
                break;
            case 'suspend':
                $until = new \DateTimeImmutable(self::MANUAL_SUSPEND_DURATION);
                if (null === $user->getMessagingSuspendedUntil() || $user->getMessagingSuspendedUntil() < $until) {
                    $user->setMessagingSuspendedUntil($until);
                }
                $this->audit($admin, 'suspend-messaging', 'user', (int) $user->getId(), $note);
                break;
            case 'ban':
                $this->ban($user, $note, $admin);
                break;
            case 'hide':
                match ($report->getTargetType()) {
                    ReportTarget::Dish => $this->hideDish($this->find(Dish::class, $report->getTargetId()), true, $note, $admin),
                    ReportTarget::Review => $this->deleteReview($this->find(Review::class, $report->getTargetId()), $note, $admin),
                    default => throw new \InvalidArgumentException('"hide" only applies to reports about a dish or a review.'),
                };
                break;
        }
        $report->resolve($applied, $note);
        $this->em->flush();
    }

    public function ban(User $user, ?string $reason, ?User $admin = null): void
    {
        if (\in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            throw new \InvalidArgumentException('An administrator cannot be banned.');
        }
        $user->ban($reason);
        $this->audit($admin, 'ban', 'user', (int) $user->getId(), $reason);
        $this->em->flush();
    }

    public function unban(User $user, ?User $admin = null): void
    {
        $user->unban();
        $this->audit($admin, 'unban', 'user', (int) $user->getId());
        $this->em->flush();
    }

    public function hideDish(Dish $dish, bool $hidden, ?string $reason = null, ?User $admin = null): void
    {
        $dish->setHiddenByModeration($hidden);
        $this->audit($admin, $hidden ? 'hide-dish' : 'unhide-dish', 'dish', (int) $dish->getId(), $reason);
        $this->em->flush();
    }

    /** Deletes a review and recomputes the dish rating. */
    public function deleteReview(Review $review, ?string $reason = null, ?User $admin = null): void
    {
        $dish = $review->getDish();
        $this->audit($admin, 'delete-review', 'review', (int) $review->getId(), $reason);
        $this->em->remove($review);
        $this->em->flush();
        if (null !== $dish) {
            $stats = $this->reviews->computeStats($dish);
            $dish->updateRatingStats($stats['average'], $stats['count']);
            $this->em->flush();
        }
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function find(string $class, int $id): object
    {
        return $this->em->find($class, $id) ?? throw new \InvalidArgumentException('The reported content no longer exists.');
    }

    private function audit(?User $admin, string $action, string $targetType, int $targetId, ?string $reason = null): void
    {
        $this->em->persist(new ModerationAction($admin, $action, $targetType, $targetId, $reason));
    }

    private function autoSuspendIfNeeded(User $reported): void
    {
        $reporters = (int) $this->db->fetchOne(
            'SELECT COUNT(DISTINCT reporter_id) FROM report WHERE reported_user_id = :u AND created_at >= :since',
            ['u' => $reported->getId(), 'since' => (new \DateTimeImmutable(self::AUTO_SUSPEND_WINDOW))->format('Y-m-d H:i:s')],
        );
        if ($reporters < self::AUTO_SUSPEND_REPORTERS || $reported->isMessagingSuspended()) {
            return;
        }
        $reported->setMessagingSuspendedUntil(new \DateTimeImmutable(self::AUTO_SUSPEND_DURATION));
        $this->em->flush();
    }

    /**
     * The reported message and up to five messages before it, kept even if the thread is later deleted.
     *
     * @return array<string, mixed>
     */
    private function threadSnapshot(DirectMessage $message): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT id, sender_id, body, created_at FROM direct_message
             WHERE ((sender_id = :a AND recipient_id = :b) OR (sender_id = :b AND recipient_id = :a)) AND id <= :id
             ORDER BY id DESC LIMIT 6',
            ['a' => $message->getSender()->getId(), 'b' => $message->getRecipient()->getId(), 'id' => $message->getId()],
        );

        return ['messages' => array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'senderId' => (int) $r['sender_id'],
            'body' => (string) $r['body'],
            'createdAt' => (string) $r['created_at'],
        ], array_reverse($rows))];
    }
}

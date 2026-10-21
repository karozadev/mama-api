<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Entity\Dish;
use App\Entity\Notification;
use App\Entity\User;
use App\Enum\NotificationType;
use App\Service\BlockService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Persists in-app notifications and pings the recipient live over their private Mercure topic.
 */
final readonly class NotificationService
{
    public function __construct(
        private EntityManagerInterface $em,
        private HubInterface $hub,
        private BlockService $blocks,
    ) {
    }

    /**
     * Records one notification; nothing happens when the actor is the recipient.
     */
    public function record(User $recipient, NotificationType $type, ?User $actor = null, ?Dish $dish = null, ?string $label = null): void
    {
        if (null !== $actor && ($actor->getId() === $recipient->getId() || $this->blocks->hasBlocked($recipient, $actor))) {
            return;
        }

        $this->em->persist((new Notification($recipient, $type, $actor, $dish))->setLabel($label));
        $this->em->flush();
        $this->ping($recipient, $type);
    }

    /**
     * Writes the daily-special notifications of one follower batch in a single flush.
     *
     * @param list<int> $followerIds
     */
    public function recordDailySpecialBatch(Dish $dish, array $followerIds): void
    {
        $actor = $dish->getAuthor();
        foreach ($followerIds as $id) {
            $this->em->persist(new Notification($this->em->getReference(User::class, $id), NotificationType::DailySpecial, $actor, $dish));
        }
        $this->em->flush();
        $this->em->clear();
    }

    /**
     * Tells the recipient's app (over their private topic) that a new direct message arrived.
     */
    public function pingMessage(User $recipient): void
    {
        $this->publish($recipient, ['type' => 'message'], 'message');
    }

    private function ping(User $recipient, NotificationType $type): void
    {
        $this->publish($recipient, ['type' => 'notification', 'kind' => $type->value], 'notification');
    }

    /**
     * @param array<string, string> $payload
     */
    private function publish(User $recipient, array $payload, string $eventType): void
    {
        try {
            $this->hub->publish(new Update(
                topics: \sprintf(MercureFollowerNotifier::TOPIC_PATTERN, $recipient->getId()),
                data: json_encode($payload, \JSON_THROW_ON_ERROR),
                private: true,
                type: $eventType,
            ));
        } catch (\Throwable) {
            // The notification is stored; the live ping is best effort.
        }
    }
}

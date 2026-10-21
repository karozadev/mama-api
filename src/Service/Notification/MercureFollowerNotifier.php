<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Entity\Dish;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Pushes a private Mercure update on each follower's personal topic.
 */
final readonly class MercureFollowerNotifier implements FollowerNotifier
{
    public const TOPIC_PATTERN = '/users/%d/notifications';

    public function __construct(private HubInterface $hub)
    {
    }

    public function notifyDailySpecial(Dish $dish, array $followerIds): void
    {
        $author = $dish->getAuthor();
        $payload = json_encode([
            'type' => 'daily_special',
            'title' => 'Le met du jour est arrivé !',
            'message' => \sprintf('%s propose : %s', $author?->getDisplayName() ?? 'Une maman', $dish->getTitle()),
            'dish' => \sprintf('/api/dishes/%d', $dish->getId()),
            'price' => $dish->getPrice(),
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);

        foreach ($followerIds as $followerId) {
            $this->hub->publish(new Update(
                topics: \sprintf(self::TOPIC_PATTERN, $followerId),
                data: $payload,
                private: true,
                type: 'daily_special',
            ));
        }
    }
}

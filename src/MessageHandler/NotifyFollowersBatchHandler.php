<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\NotifyFollowersBatch;
use App\Repository\DishRepository;
use App\Service\Notification\FollowerNotifier;
use App\Service\Notification\NotificationService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class NotifyFollowersBatchHandler
{
    public function __construct(
        private DishRepository $dishes,
        private FollowerNotifier $notifier,
        private NotificationService $notifications,
    ) {
    }

    public function __invoke(NotifyFollowersBatch $message): void
    {
        $dish = $this->dishes->find($message->dishId);
        if (null === $dish) {
            return;
        }

        $this->notifier->notifyDailySpecial($dish, $message->followerIds);
        $this->notifications->recordDailySpecialBatch($dish, $message->followerIds);
    }
}

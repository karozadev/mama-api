<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Enum\DishVisibility;
use App\Message\NotifyFollowersBatch;
use App\Message\SendDailySpecialNotification;
use App\Repository\DishRepository;
use App\Repository\UserRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class SendDailySpecialNotificationHandler
{
    public const BATCH_SIZE = 200;

    public function __construct(
        private DishRepository $dishes,
        private UserRepository $users,
        private MessageBusInterface $bus,
    ) {
    }

    public function __invoke(SendDailySpecialNotification $message): void
    {
        $dish = $this->dishes->find($message->dishId);
        $author = $dish?->getAuthor();
        if (null === $dish || null === $author || DishVisibility::Private === $dish->getVisibility()) {
            return;
        }

        foreach (array_chunk($this->users->findFollowerIds($author), self::BATCH_SIZE) as $batch) {
            $this->bus->dispatch(new NotifyFollowersBatch($message->dishId, $batch));
        }
    }
}

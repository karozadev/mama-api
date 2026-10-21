<?php

declare(strict_types=1);

namespace App\Doctrine;

use App\Entity\Dish;
use App\Message\SendDailySpecialNotification;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Queues follower notifications for new daily specials. Dispatching happens after the
 * flush so that asynchronous workers never read a dish that is not yet committed.
 */
#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postFlush)]
final class DailySpecialNotificationListener
{
    /** @var list<int> */
    private array $pendingDishIds = [];

    public function __construct(private readonly MessageBusInterface $bus)
    {
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $entity = $args->getObject();
        if ($entity instanceof Dish && $entity->isDailySpecial() && null !== $entity->getId()) {
            $this->pendingDishIds[] = $entity->getId();
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        $ids = $this->pendingDishIds;
        $this->pendingDishIds = [];

        foreach ($ids as $dishId) {
            $this->bus->dispatch(new SendDailySpecialNotification($dishId));
        }
    }
}

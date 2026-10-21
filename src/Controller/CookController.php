<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Dish;
use App\Entity\User;
use App\Enum\NotificationType;
use App\Service\DishAccess;
use App\Service\Notification\NotificationService;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class CookController
{
    public function __construct(
        private readonly DishAccess $access,
        private readonly NotificationService $notifications,
        private readonly Connection $db,
    ) {
    }

    /**
     * "Je cuisine ce met": tells the author (once per day and cook) that her dish is being cooked.
     */
    #[Route('/api/dishes/{id<\d+>}/cook', name: 'api_dish_cook', methods: ['POST'])]
    public function cook(Dish $dish, #[CurrentUser] User $user): JsonResponse
    {
        $this->access->assertCanView($dish, $user);
        $author = $dish->getAuthor();
        if (null !== $author && $author->getId() !== $user->getId()) {
            $alreadyToday = false !== $this->db->fetchOne(
                'SELECT 1 FROM notification WHERE recipient_id = :author AND actor_id = :actor AND dish_id = :dish AND type = :type AND created_at >= :since',
                ['author' => $author->getId(), 'actor' => $user->getId(), 'dish' => $dish->getId(), 'type' => NotificationType::Cook->value, 'since' => (new \DateTimeImmutable('today'))->format('Y-m-d H:i:s')],
            );
            if (!$alreadyToday) {
                $this->notifications->record($author, NotificationType::Cook, $user, $dish);
            }
        }

        return new JsonResponse(['cooking' => true]);
    }
}

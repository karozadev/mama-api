<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Dish;
use App\Entity\DishLike;
use App\Entity\User;
use App\Enum\NotificationType;
use App\Repository\DishLikeRepository;
use App\Service\DishAccess;
use App\Service\Notification\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class LikeController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DishLikeRepository $likes,
        private readonly NotificationService $notifications,
        private readonly DishAccess $access,
    ) {
    }

    #[Route('/api/dishes/{id<\d+>}/like', name: 'api_dish_like', methods: ['POST'])]
    public function like(Dish $dish, #[CurrentUser] User $user): JsonResponse
    {
        $this->access->assertCanView($dish, $user);
        $isNew = null === $this->likes->findOneBy(['user' => $user, 'dish' => $dish]);
        if ($isNew) {
            $this->em->persist(new DishLike($user, $dish));
            $this->em->flush();
        }
        $count = $this->refreshCount($dish);

        if ($isNew && null !== $dish->getAuthor()) {
            $this->notifications->record($dish->getAuthor(), NotificationType::Like, $user, $dish);
        }

        return new JsonResponse(['liked' => true, 'likeCount' => $count]);
    }

    #[Route('/api/dishes/{id<\d+>}/like', name: 'api_dish_unlike', methods: ['DELETE'])]
    public function unlike(Dish $dish, #[CurrentUser] User $user): JsonResponse
    {
        $like = $this->likes->findOneBy(['user' => $user, 'dish' => $dish]);
        if (null !== $like) {
            $this->em->remove($like);
            $this->em->flush();
        }

        return new JsonResponse(['liked' => false, 'likeCount' => $this->refreshCount($dish)]);
    }

    private function refreshCount(Dish $dish): int
    {
        $count = $this->likes->countFor($dish);
        $dish->setLikeCount($count);
        $this->em->flush();

        return $count;
    }
}

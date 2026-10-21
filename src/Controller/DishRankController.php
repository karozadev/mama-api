<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Dish;
use App\Entity\User;
use App\Service\DishAccess;
use App\Service\DishRanking;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class DishRankController
{
    public function __construct(
        private readonly DishRanking $ranking,
        private readonly DishAccess $access,
        private readonly Security $security,
    ) {
    }

    /**
     * Rank of a dish in today's Top 10 ("#1 aujourd'hui"), or null.
     */
    #[Route('/api/dishes/{id<\d+>}/rank', name: 'api_dish_rank', methods: ['GET'])]
    public function rank(Dish $dish): JsonResponse
    {
        $viewer = $this->security->getUser();
        $this->access->assertCanView($dish, $viewer instanceof User ? $viewer : null);

        return new JsonResponse(['rank' => $this->ranking->rankOf((int) $dish->getId())]);
    }
}

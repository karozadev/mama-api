<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\DishRepository;
use App\Repository\UserRepository;
use App\Service\DishRanking;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public counters shown on the welcome screen.
 */
final class StatsController
{
    #[Route('/api/stats', name: 'api_stats', methods: ['GET'])]
    public function __invoke(UserRepository $users, DishRepository $dishes, DishRanking $ranking): JsonResponse
    {
        $headline = $ranking->headline();
        $response = new JsonResponse([
            'mamas' => $users->count(['bannedAt' => null]),
            'dishes' => $dishes->count([]),
            'topDish' => null === $headline ? null : ['id' => $headline['id'], 'title' => $headline['title']],
        ]);
        $response->setSharedMaxAge(300);

        return $response;
    }
}

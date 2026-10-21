<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\BlockService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class BlockController
{
    public function __construct(private readonly BlockService $blocks)
    {
    }

    #[Route('/api/users/{id<\d+>}/block', name: 'api_user_block', methods: ['POST'])]
    public function block(User $target, #[CurrentUser] User $me): JsonResponse
    {
        if ($target->getId() === $me->getId()) {
            return new JsonResponse(['title' => 'Action impossible', 'detail' => 'Vous ne pouvez pas vous bloquer vous-même.', 'status' => 422], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->blocks->block($me, $target);

        return new JsonResponse(['blocked' => true]);
    }

    #[Route('/api/users/{id<\d+>}/block', name: 'api_user_unblock', methods: ['DELETE'])]
    public function unblock(User $target, #[CurrentUser] User $me): JsonResponse
    {
        $this->blocks->unblock($me, $target);

        return new JsonResponse(['blocked' => false]);
    }

    #[Route('/api/me/blocks', name: 'api_me_blocks', methods: ['GET'])]
    public function mine(#[CurrentUser] User $me): JsonResponse
    {
        return new JsonResponse($this->blocks->blockedBy($me));
    }
}

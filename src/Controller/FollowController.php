<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Enum\NotificationType;
use App\Service\BlockService;
use App\Service\Notification\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class FollowController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NotificationService $notifications,
        private readonly BlockService $blocks,
    ) {
    }

    #[Route('/api/users/{id<\d+>}/follow', name: 'api_user_follow', methods: ['POST'])]
    public function follow(User $mother, #[CurrentUser] User $user): JsonResponse
    {
        if ($mother->getId() === $user->getId()) {
            return new JsonResponse(['detail' => 'Vous ne pouvez pas vous suivre vous-même.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($mother->isBanned()) {
            throw new NotFoundHttpException('Maman introuvable.');
        }
        if ($this->blocks->isBlockedEitherWay($user, $mother)) {
            return new JsonResponse(['title' => 'Action impossible', 'detail' => 'Vous ne pouvez pas suivre cette maman.', 'status' => 403], Response::HTTP_FORBIDDEN);
        }
        $isNew = !$user->isFollowing($mother);
        $user->follow($mother);
        $this->em->flush();
        if ($isNew) {
            $this->notifications->record($mother, NotificationType::Follow, $user);
        }

        return new JsonResponse(['following' => true, 'followersCount' => $mother->getFollowersCount()]);
    }

    #[Route('/api/users/{id<\d+>}/follow', name: 'api_user_unfollow', methods: ['DELETE'])]
    public function unfollow(User $mother, #[CurrentUser] User $user): JsonResponse
    {
        $user->unfollow($mother);
        $this->em->flush();

        return new JsonResponse(['following' => false, 'followersCount' => $mother->getFollowersCount()]);
    }
}

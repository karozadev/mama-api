<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Dish;
use App\Entity\ModerationAction;
use App\Entity\Review;
use App\Entity\User;
use App\Service\ModerationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Direct moderator actions (ROLE_ADMIN), each one recorded in the audit trail.
 */
#[IsGranted('ROLE_ADMIN')]
final class AdminModerationController
{
    public function __construct(
        private readonly ModerationService $moderation,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** Body: {"reason": "…"}. The account cannot log in and all its content disappears. */
    #[Route('/api/admin/users/{id<\d+>}/ban', name: 'api_admin_user_ban', methods: ['POST'])]
    public function ban(User $target, Request $request, #[CurrentUser] User $admin): JsonResponse
    {
        if ($target->getId() === $admin->getId()) {
            return $this->error('Vous ne pouvez pas vous bannir vous-même.');
        }
        try {
            $this->moderation->ban($target, $this->reason($request), $admin);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        }

        return new JsonResponse(['banned' => true]);
    }

    #[Route('/api/admin/users/{id<\d+>}/ban', name: 'api_admin_user_unban', methods: ['DELETE'])]
    public function unban(User $target, #[CurrentUser] User $admin): JsonResponse
    {
        $this->moderation->unban($target, $admin);

        return new JsonResponse(['banned' => false]);
    }

    #[Route('/api/admin/dishes/{id<\d+>}/hide', name: 'api_admin_dish_hide', methods: ['POST'])]
    public function hide(Dish $dish, Request $request, #[CurrentUser] User $admin): JsonResponse
    {
        $this->moderation->hideDish($dish, true, $this->reason($request), $admin);

        return new JsonResponse(['hidden' => true]);
    }

    #[Route('/api/admin/dishes/{id<\d+>}/hide', name: 'api_admin_dish_unhide', methods: ['DELETE'])]
    public function unhide(Dish $dish, #[CurrentUser] User $admin): JsonResponse
    {
        $this->moderation->hideDish($dish, false, null, $admin);

        return new JsonResponse(['hidden' => false]);
    }

    #[Route('/api/admin/reviews/{id<\d+>}', name: 'api_admin_review_delete', methods: ['DELETE'])]
    public function deleteReview(Review $review, Request $request, #[CurrentUser] User $admin): Response
    {
        $this->moderation->deleteReview($review, $this->reason($request), $admin);

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    /** The 100 latest moderator actions. */
    #[Route('/api/admin/actions', name: 'api_admin_actions', methods: ['GET'])]
    public function actions(): JsonResponse
    {
        $actions = $this->em->getRepository(ModerationAction::class)->findBy([], ['id' => 'DESC'], 100);

        return new JsonResponse(array_map(static fn (ModerationAction $a): array => [
            'id' => $a->getId(),
            'at' => $a->getCreatedAt()->format(\DATE_ATOM),
            'admin' => $a->getAdmin()?->getDisplayName(),
            'action' => $a->getAction(),
            'target' => $a->getTargetType().'#'.$a->getTargetId(),
            'reason' => $a->getReason(),
        ], $actions));
    }

    private function reason(Request $request): ?string
    {
        $payload = '' === $request->getContent() ? [] : $request->toArray();

        return \is_string($payload['reason'] ?? null) ? $payload['reason'] : null;
    }

    private function error(string $detail): JsonResponse
    {
        return new JsonResponse(['title' => 'Action impossible', 'detail' => $detail, 'status' => 422], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}

<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\DirectMessage;
use App\Entity\Dish;
use App\Entity\Review;
use App\Entity\User;
use App\Enum\ReportReason;
use App\Enum\ReportTarget;
use App\Service\DishAccess;
use App\Service\ModerationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class ReportController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ModerationService $moderation,
        private readonly DishAccess $access,
        #[Autowire(service: 'limiter.report')]
        private readonly RateLimiterFactory $reportLimiter,
    ) {
    }

    /**
     * Body: {"type": "user|dish|review|message", "id": 12, "reason": "spam|harassment|inappropriate|scam|other", "details": "…"}
     */
    #[Route('/api/reports', name: 'api_report_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] User $me): JsonResponse
    {
        if (!$this->reportLimiter->create((string) $me->getId())->consume()->isAccepted()) {
            return $this->error('Vous avez envoyé beaucoup de signalements aujourd\'hui. Réessayez demain.', Response::HTTP_TOO_MANY_REQUESTS);
        }

        $payload = $request->toArray();
        $type = ReportTarget::tryFrom((string) ($payload['type'] ?? ''));
        $reason = ReportReason::tryFrom((string) ($payload['reason'] ?? ''));
        $id = \is_int($payload['id'] ?? null) ? $payload['id'] : 0;
        if (null === $type || null === $reason || $id < 1) {
            return $this->error('Signalement invalide.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $details = \is_string($payload['details'] ?? null) ? $payload['details'] : null;

        $message = null;
        $reported = match ($type) {
            ReportTarget::User => $this->em->find(User::class, $id),
            ReportTarget::Dish => $this->reportableDish($id, $me)?->getAuthor(),
            ReportTarget::Review => $this->reportableReview($id, $me)?->getAuthor(),
            ReportTarget::Message => ($message = $this->receivedMessage($id, $me))?->getSender(),
        };
        if (null === $reported) {
            return $this->error('Ce contenu est introuvable.', Response::HTTP_NOT_FOUND);
        }
        if ($reported->getId() === $me->getId()) {
            return $this->error('Vous ne pouvez pas vous signaler vous-même.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $report = $this->moderation->report($me, $reported, $type, $id, $reason, $details, $message);

        return new JsonResponse(['reported' => true, 'duplicate' => null === $report], null === $report ? Response::HTTP_OK : Response::HTTP_CREATED);
    }

    private function reportableDish(int $id, User $me): ?Dish
    {
        $dish = $this->em->find(Dish::class, $id);

        return null !== $dish && $this->access->canView($dish, $me) ? $dish : null;
    }

    private function reportableReview(int $id, User $me): ?Review
    {
        $review = $this->em->find(Review::class, $id);
        $dish = $review?->getDish();

        return null !== $review && null !== $dish && $this->access->canView($dish, $me) ? $review : null;
    }

    /** Only the recipient of a message may report it. */
    private function receivedMessage(int $id, User $me): ?DirectMessage
    {
        $message = $this->em->find(DirectMessage::class, $id);

        return null !== $message && $message->getRecipient()->getId() === $me->getId() ? $message : null;
    }

    private function error(string $detail, int $status): JsonResponse
    {
        return new JsonResponse(['title' => 'Signalement refusé', 'detail' => $detail, 'status' => $status], $status);
    }
}

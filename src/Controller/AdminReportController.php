<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Report;
use App\Entity\User;
use App\Enum\ReportStatus;
use App\Service\ModerationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Moderator endpoints (ROLE_ADMIN): back-office building blocks.
 */
#[IsGranted('ROLE_ADMIN')]
final class AdminReportController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ModerationService $moderation,
        private readonly Security $security,
    ) {
    }

    #[Route('/api/admin/reports', name: 'api_admin_reports', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $status = ReportStatus::tryFrom((string) $request->query->get('status', 'open')) ?? ReportStatus::Open;
        $reports = $this->em->getRepository(Report::class)->findBy(['status' => $status], ['createdAt' => 'ASC'], 50);

        return new JsonResponse(array_map(static fn (Report $r): array => [
            'id' => $r->getId(),
            'type' => $r->getTargetType()->value,
            'targetId' => $r->getTargetId(),
            'reason' => $r->getReason()->value,
            'details' => $r->getDetails(),
            'status' => $r->getStatus()->value,
            'createdAt' => $r->getCreatedAt()->format(\DATE_ATOM),
            'reporter' => ['id' => $r->getReporter()->getId(), 'displayName' => $r->getReporter()->getDisplayName()],
            'reportedUser' => [
                'id' => $r->getReportedUser()->getId(),
                'displayName' => $r->getReportedUser()->getDisplayName(),
                'messagingSuspendedUntil' => $r->getReportedUser()->getMessagingSuspendedUntil()?->format(\DATE_ATOM),
            ],
            'context' => $r->getContext(),
        ], $reports));
    }

    /**
     * Body: {"action": "dismiss|suspend|hide|ban", "note": "…"}
     */
    #[Route('/api/admin/reports/{id<\d+>}/resolve', name: 'api_admin_report_resolve', methods: ['POST'])]
    public function resolve(Report $report, Request $request): JsonResponse
    {
        $payload = $request->toArray();
        $action = $payload['action'] ?? null;
        if (!\is_string($action) || !\in_array($action, ModerationService::RESOLUTIONS, true)) {
            return new JsonResponse(['title' => 'Action invalide', 'detail' => 'Utilisez : '.implode(', ', ModerationService::RESOLUTIONS).'.', 'status' => 422], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        try {
            $this->moderation->resolve($report, $action, \is_string($payload['note'] ?? null) ? $payload['note'] : null, $this->security->getUser() instanceof User ? $this->security->getUser() : null);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['title' => 'Action impossible', 'detail' => $e->getMessage(), 'status' => 422], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['id' => $report->getId(), 'status' => $report->getStatus()->value]);
    }
}

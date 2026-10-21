<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\DirectMessage;
use App\Entity\User;
use App\Repository\DirectMessageRepository;
use App\Service\BlockService;
use App\Service\Notification\NotificationService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * One-to-one messages between mothers who know each other (one follows the other, or a thread already exists).
 */
#[IsGranted('ROLE_USER')]
final class MessageController
{
    private const PAGE = 30;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $db,
        private readonly DirectMessageRepository $messages,
        private readonly NotificationService $notifications,
        private readonly BlockService $blocks,
        #[Autowire(service: 'limiter.message')]
        private readonly RateLimiterFactory $messageLimiter,
    ) {
    }

    #[Route('/api/me/conversations', name: 'api_me_conversations', methods: ['GET'])]
    public function conversations(#[CurrentUser] User $me): JsonResponse
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT o.id, o.display_name, o.avatar_url, m.body, m.created_at, m.sender_id,
                    (SELECT COUNT(*) FROM direct_message u WHERE u.recipient_id = :me AND u.sender_id = o.id AND u.read_at IS NULL) AS unread
             FROM (
                 SELECT DISTINCT ON (LEAST(sender_id, recipient_id), GREATEST(sender_id, recipient_id)) id
                 FROM direct_message
                 WHERE sender_id = :me OR recipient_id = :me
                 ORDER BY LEAST(sender_id, recipient_id), GREATEST(sender_id, recipient_id), created_at DESC, id DESC
             ) last
             JOIN direct_message m ON m.id = last.id
             JOIN "user" o ON o.id = CASE WHEN m.sender_id = :me THEN m.recipient_id ELSE m.sender_id END
             WHERE NOT EXISTS (SELECT 1 FROM user_block b WHERE b.blocker_id = :me AND b.blocked_id = o.id)
             ORDER BY m.created_at DESC, m.id DESC
             LIMIT 50',
            ['me' => $me->getId()],
        );

        return new JsonResponse(array_map(static fn (array $r): array => [
            'user' => ['id' => (int) $r['id'], 'displayName' => (string) $r['display_name'], 'avatarUrl' => $r['avatar_url']],
            'lastMessage' => (string) $r['body'],
            'lastAt' => (new \DateTimeImmutable((string) $r['created_at']))->format(\DATE_ATOM),
            'lastIsMine' => (int) $r['sender_id'] === (int) $me->getId(),
            'unread' => (int) $r['unread'],
        ], $rows));
    }

    /**
     * Newest first; pass ?before=<messageId> for the previous page.
     */
    #[Route('/api/conversations/{id<\d+>}/messages', name: 'api_conversation_messages', methods: ['GET'])]
    public function history(User $other, Request $request, #[CurrentUser] User $me): JsonResponse
    {
        $before = $request->query->getInt('before', \PHP_INT_MAX);
        $rows = $this->db->fetchAllAssociative(
            'SELECT id, sender_id, body, created_at, read_at FROM direct_message
             WHERE ((sender_id = :me AND recipient_id = :other) OR (sender_id = :other AND recipient_id = :me)) AND id < CAST(:before AS bigint)
             ORDER BY id DESC LIMIT '.(self::PAGE + 1),
            ['me' => $me->getId(), 'other' => $other->getId(), 'before' => $before],
        );
        $hasMore = \count($rows) > self::PAGE;

        return new JsonResponse([
            'hasMore' => $hasMore,
            'canMessage' => $this->canMessage($me, $other),
            'blockedByMe' => $this->blocks->hasBlocked($me, $other),
            'items' => array_map(fn (array $r): array => $this->row($r, $me), \array_slice($rows, 0, self::PAGE)),
        ]);
    }

    #[Route('/api/conversations/{id<\d+>}/messages', name: 'api_conversation_send', methods: ['POST'])]
    public function send(User $other, Request $request, #[CurrentUser] User $me): JsonResponse
    {
        if ($other->getId() === $me->getId()) {
            return $this->error('Vous ne pouvez pas vous écrire à vous-même.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($other->isBanned()) {
            return $this->error('Cette maman n\'est plus disponible.', Response::HTTP_NOT_FOUND);
        }
        if ($me->isMessagingSuspended()) {
            return $this->error('Votre messagerie est suspendue pour le moment.', Response::HTTP_FORBIDDEN);
        }
        if (!$this->canMessage($me, $other)) {
            return $this->error($this->blocks->isBlockedEitherWay($me, $other)
                ? 'Vous ne pouvez pas écrire à cette maman.'
                : \sprintf('Suivez %s pour pouvoir lui écrire.', $other->getDisplayName()), Response::HTTP_FORBIDDEN);
        }
        if (!$this->messageLimiter->create((string) $me->getId())->consume()->isAccepted()) {
            return $this->error('Vous écrivez trop vite, patientez un instant.', Response::HTTP_TOO_MANY_REQUESTS);
        }

        $payload = $request->toArray();
        $body = \is_string($payload['body'] ?? null) ? trim($payload['body']) : '';
        if ('' === $body || mb_strlen($body) > DirectMessage::MAX_LENGTH) {
            return $this->error(\sprintf('Le message doit contenir entre 1 et %d caractères.', DirectMessage::MAX_LENGTH), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $message = new DirectMessage($me, $other, $body);
        $this->em->persist($message);
        $this->em->flush();
        $this->notifications->pingMessage($other);

        return new JsonResponse([
            'id' => $message->getId(),
            'mine' => true,
            'body' => $body,
            'createdAt' => $message->getCreatedAt()->format(\DATE_ATOM),
            'read' => false,
        ], Response::HTTP_CREATED);
    }

    #[Route('/api/conversations/{id<\d+>}/read', name: 'api_conversation_read', methods: ['POST'])]
    public function markRead(User $other, #[CurrentUser] User $me): Response
    {
        $this->db->executeStatement(
            'UPDATE direct_message SET read_at = :now WHERE recipient_id = :me AND sender_id = :other AND read_at IS NULL',
            ['now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'), 'me' => $me->getId(), 'other' => $other->getId()],
        );

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    /**
     * Allowed when one of the two follows the other, or when they already exchanged messages.
     */
    private function canMessage(User $me, User $other): bool
    {
        if ($this->blocks->isBlockedEitherWay($me, $other)) {
            return false;
        }
        if ($me->isFollowing($other) || $other->isFollowing($me)) {
            return true;
        }

        return null !== $this->messages->findOneBy(['sender' => $other, 'recipient' => $me]);
    }

    /**
     * @param array<string, mixed> $r
     *
     * @return array<string, mixed>
     */
    private function row(array $r, User $me): array
    {
        return [
            'id' => (int) $r['id'],
            'mine' => (int) $r['sender_id'] === (int) $me->getId(),
            'body' => (string) $r['body'],
            'createdAt' => (new \DateTimeImmutable((string) $r['created_at']))->format(\DATE_ATOM),
            'read' => null !== $r['read_at'],
        ];
    }

    private function error(string $detail, int $status): JsonResponse
    {
        return new JsonResponse(['title' => 'Message refusé', 'detail' => $detail, 'status' => $status], $status);
    }
}

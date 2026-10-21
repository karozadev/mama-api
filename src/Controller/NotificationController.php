<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Notification;
use App\Entity\User;
use App\Enum\NotificationType;
use App\Repository\NotificationRepository;
use App\Service\DishRanking;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class NotificationController
{
    private const PER_PAGE = 30;

    public function __construct(
        private readonly NotificationRepository $notifications,
        private readonly DishRanking $ranking,
    ) {
    }

    #[Route('/api/me/notifications', name: 'api_me_notifications', methods: ['GET'])]
    public function list(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $page = max(1, $request->query->getInt('page', 1));
        $filter = (string) $request->query->get('filter', 'all');
        // Tabs of the screen: everything, "Mentions" (reviews and collections) and "Marché" (people cooking my dishes).
        $types = match ($filter) {
            'mentions' => [NotificationType::Review, NotificationType::Collection],
            'market' => [NotificationType::Cook],
            default => null,
        };
        $items = $this->notifications->findPage($user, $page, self::PER_PAGE, $types);

        return new JsonResponse([
            'unread' => $this->notifications->countUnread($user),
            'hasMore' => \count($items) === self::PER_PAGE,
            'ranking' => 1 === $page && null === $types ? $this->ranking->bestOfAuthor((int) $user->getId()) : null,
            'items' => $this->group($items),
        ]);
    }

    #[Route('/api/me/notifications/read', name: 'api_me_notifications_read', methods: ['POST'])]
    public function markAllRead(#[CurrentUser] User $user): Response
    {
        $this->notifications->markAllRead($user);

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    /**
     * Hearts on the same dish on the same day become one line ("Mama Rina et 12 autres ont aimé…").
     *
     * @param list<Notification> $items
     *
     * @return list<array<string, mixed>>
     */
    private function group(array $items): array
    {
        $out = [];
        $likeIndex = [];
        foreach ($items as $n) {
            if (NotificationType::Like === $n->getType() && null !== $n->getDish()) {
                $key = $n->getDish()->getId().'|'.$n->getCreatedAt()->format('Y-m-d');
                if (isset($likeIndex[$key])) {
                    ++$out[$likeIndex[$key]]['othersCount'];
                    $out[$likeIndex[$key]]['read'] = $out[$likeIndex[$key]]['read'] && $n->isRead();

                    continue;
                }
                $likeIndex[$key] = \count($out);
            }
            $out[] = $this->serialize($n);
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(Notification $n): array
    {
        $actor = $n->getActor();
        $dish = $n->getDish();

        return [
            'id' => $n->getId(),
            'type' => $n->getType()->value,
            'read' => $n->isRead(),
            'label' => $n->getLabel(),
            'othersCount' => 0,
            'createdAt' => $n->getCreatedAt()->format(\DATE_ATOM),
            'actor' => null === $actor ? null : [
                'id' => $actor->getId(),
                'displayName' => $actor->getDisplayName(),
                'avatarUrl' => $actor->getAvatarUrl(),
            ],
            'dish' => null === $dish ? null : [
                'id' => $dish->getId(),
                'title' => $dish->getTitle(),
                'photoUrl' => $dish->getPhotoUrl(),
                'price' => $dish->getPrice(),
            ],
        ];
    }
}

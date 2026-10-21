<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\DirectMessageRepository;
use App\Repository\DishLikeRepository;
use App\Repository\NotificationRepository;
use App\Repository\UserRepository;
use App\Service\Notification\MercureFollowerNotifier;
use App\Service\Upload\ImageUploader;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mercure\Jwt\Grant;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class MeController
{
    public function __construct(
        private readonly NotificationRepository $notifications,
        private readonly DishLikeRepository $likes,
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly ImageUploader $uploader,
        private readonly DirectMessageRepository $messageRepository,
    ) {
    }

    #[Route('/api/me', name: 'api_me', methods: ['GET'])]
    public function me(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse([
            'id' => $user->getId(),
            'iri' => \sprintf('/api/users/%d', $user->getId()),
            'email' => $user->getEmail(),
            'displayName' => $user->getDisplayName(),
            'avatarUrl' => $user->getAvatarUrl(),
            'city' => $user->getCity(),
            'bio' => $user->getBio(),
            'dishCount' => $user->getTotalDishCount(),
            'favoritesPublic' => $user->isFavoritesPublic(),
            'unreadNotifications' => $this->notifications->countUnread($user),
            'messagingSuspendedUntil' => $user->isMessagingSuspended() ? $user->getMessagingSuspendedUntil()?->format(\DATE_ATOM) : null,
            'unreadMessages' => $this->messageRepository->countUnreadFor($user),
            'roles' => $user->getRoles(),
            'plan' => $user->getPlan()->value,
            'followingCount' => $user->getFollowingCount(),
            'followersCount' => $user->getFollowersCount(),
        ]);
    }

    /**
     * Ids of the mothers the current user follows (used by the app to build the feed and follow buttons).
     */
    #[Route('/api/me/following', name: 'api_me_following', methods: ['GET'])]
    public function following(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse([
            'ids' => $user->getFollowing()->map(static fn (User $mother): ?int => $mother->getId())->getValues(),
            'mothers' => $this->users->findFollowedMothers($user),
        ]);
    }

    /**
     * Permanently deletes the account and everything attached to it (dishes, reviews, hearts, follows,
     * messages, collections, notifications) plus the uploaded photos. Body: {"password": "…"}.
     * Required by the app stores; irreversible.
     */
    #[Route('/api/me', name: 'api_me_delete', methods: ['DELETE'])]
    public function deleteAccount(Request $request, #[CurrentUser] User $user): Response
    {
        $payload = $request->getContent() === '' ? [] : $request->toArray();
        $password = \is_string($payload['password'] ?? null) ? $payload['password'] : '';
        if ('' === $password || !$this->hasher->isPasswordValid($user, $password)) {
            return new JsonResponse(['title' => 'Mot de passe incorrect', 'detail' => 'Confirmez la suppression avec votre mot de passe.', 'status' => 403], Response::HTTP_FORBIDDEN);
        }

        $photos = [$user->getAvatarUrl()];
        foreach ($user->getDishes() as $dish) {
            $photos[] = $dish->getPhotoUrl();
        }
        $this->em->remove($user); // every relation cascades at database level
        $this->em->flush();
        foreach ($photos as $url) {
            $this->uploader->deleteByUrl($url);
        }

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    /**
     * Ids of the dishes the current user has liked.
     */
    #[Route('/api/me/likes', name: 'api_me_likes', methods: ['GET'])]
    public function likedDishes(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse(['ids' => $this->likes->likedDishIds($user)]);
    }

    /**
     * Short-lived token the mobile app uses to subscribe to its private notification topic.
     */
    #[Route('/api/me/mercure-token', name: 'api_me_mercure_token', methods: ['GET'])]
    public function mercureToken(
        #[CurrentUser] User $user,
        #[Autowire(service: 'mercure.hub.default.jwt.factory')]
        TokenFactoryInterface $tokenFactory,
        #[Autowire('%env(MERCURE_PUBLIC_URL)%')]
        string $hubUrl,
    ): JsonResponse {
        $topic = \sprintf(MercureFollowerNotifier::TOPIC_PATTERN, $user->getId());

        return new JsonResponse([
            'hubUrl' => $hubUrl,
            'topic' => $topic,
            'token' => $tokenFactory->create([new Grant([Grant::ACTION_SUBSCRIBE], [$topic])]),
        ]);
    }
}

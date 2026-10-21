<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Bookmark;
use App\Entity\Dish;
use App\Entity\User;
use App\Repository\BookmarkRepository;
use App\Service\DishAccess;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

#[IsGranted('ROLE_USER')]
final class BookmarkController
{
    private const PER_PAGE = 30;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BookmarkRepository $bookmarks,
        private readonly NormalizerInterface $normalizer,
        private readonly DishAccess $access,
    ) {
    }

    #[Route('/api/dishes/{id<\d+>}/bookmark', name: 'api_dish_bookmark', methods: ['POST'])]
    public function add(Dish $dish, #[CurrentUser] User $user): JsonResponse
    {
        $this->access->assertCanView($dish, $user);
        if (null === $this->bookmarks->findOneBy(['user' => $user, 'dish' => $dish])) {
            $this->em->persist(new Bookmark($user, $dish));
            $this->em->flush();
        }

        return new JsonResponse(['bookmarked' => true]);
    }

    #[Route('/api/dishes/{id<\d+>}/bookmark', name: 'api_dish_unbookmark', methods: ['DELETE'])]
    public function remove(Dish $dish, #[CurrentUser] User $user): JsonResponse
    {
        $bookmark = $this->bookmarks->findOneBy(['user' => $user, 'dish' => $dish]);
        if (null !== $bookmark) {
            $this->em->remove($bookmark);
            $this->em->flush();
        }

        return new JsonResponse(['bookmarked' => false]);
    }

    /**
     * Ids of every bookmarked dish (to draw the bookmark icons).
     */
    #[Route('/api/me/bookmarks', name: 'api_me_bookmarks', methods: ['GET'])]
    public function ids(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse(['ids' => $this->bookmarks->bookmarkedDishIds($user)]);
    }

    /**
     * Another mother's favourites, when she shares them; limited to what the viewer may see.
     */
    #[Route('/api/users/{id<\d+>}/bookmarks', name: 'api_user_bookmarks', methods: ['GET'])]
    public function ofUser(User $owner, Request $request, #[CurrentUser] User $viewer): JsonResponse
    {
        if ($owner->isBanned()) {
            throw new NotFoundHttpException('Maman introuvable.');
        }
        if ($owner->getId() !== $viewer->getId() && !$owner->isFavoritesPublic()) {
            return new JsonResponse(['isPrivate' => true, 'dishes' => []]);
        }
        $page = max(1, $request->query->getInt('page', 1));
        $dishes = $this->bookmarks->findDishes($owner, $page, self::PER_PAGE, $this->access, $viewer);

        return new JsonResponse(['isPrivate' => false, 'dishes' => $this->normalizer->normalize($dishes, 'json', ['groups' => ['dish:read']])]);
    }

    /**
     * The bookmarked dishes themselves, newest bookmark first, in the same shape as /api/dishes.
     */
    #[Route('/api/me/bookmarks/dishes', name: 'api_me_bookmarked_dishes', methods: ['GET'])]
    public function dishes(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $page = max(1, $request->query->getInt('page', 1));
        $dishes = $this->bookmarks->findDishes($user, $page, self::PER_PAGE, $this->access);

        return new JsonResponse($this->normalizer->normalize($dishes, 'json', ['groups' => ['dish:read']]));
    }
}

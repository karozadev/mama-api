<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Dish;
use App\Entity\DishCollection;
use App\Entity\DishCollectionItem;
use App\Entity\User;
use App\Enum\NotificationType;
use App\Repository\DishCollectionItemRepository;
use App\Repository\DishCollectionRepository;
use App\Service\DishAccess;
use App\Service\Notification\NotificationService;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

#[IsGranted('ROLE_USER')]
final class CollectionController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $db,
        private readonly DishCollectionRepository $collections,
        private readonly DishCollectionItemRepository $items,
        private readonly DishAccess $access,
        private readonly NormalizerInterface $normalizer,
        private readonly NotificationService $notifications,
    ) {
    }

    /**
     * My collections. With ?dish=ID each one tells whether it already contains that dish.
     */
    #[Route('/api/me/collections', name: 'api_me_collections', methods: ['GET'])]
    public function mine(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $dishId = $request->query->has('dish') ? $request->query->getInt('dish') : null;

        return new JsonResponse($this->summaries($user, $user, false, $dishId));
    }

    /**
     * Public collections of another mother.
     */
    #[Route('/api/users/{id<\d+>}/collections', name: 'api_user_collections', methods: ['GET'])]
    public function ofUser(User $owner, #[CurrentUser] User $viewer): JsonResponse
    {
        if ($owner->isBanned()) {
            throw new NotFoundHttpException('Maman introuvable.');
        }
        return new JsonResponse($this->summaries($owner, $viewer, $owner->getId() !== $viewer->getId()));
    }

    #[Route('/api/collections', name: 'api_collection_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $payload = $request->toArray();
        $name = $this->cleanName($payload['name'] ?? null);
        if (null === $name) {
            return $this->error('Donnez un nom à votre collection (80 caractères maximum).');
        }
        if ($this->collections->count(['owner' => $user]) >= DishCollection::MAX_PER_USER) {
            return $this->error('Vous avez atteint le nombre maximum de collections.');
        }

        $collection = new DishCollection($user, $name, (bool) ($payload['isPublic'] ?? true));
        $this->em->persist($collection);
        $this->em->flush();

        return new JsonResponse($this->summary($collection, 0, [], false), Response::HTTP_CREATED);
    }

    #[Route('/api/collections/{id<\d+>}', name: 'api_collection_update', methods: ['PATCH'])]
    public function update(DishCollection $collection, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $this->assertOwner($collection, $user);
        $payload = $request->toArray();
        if (\array_key_exists('name', $payload)) {
            $name = $this->cleanName($payload['name']);
            if (null === $name) {
                return $this->error('Donnez un nom à votre collection (80 caractères maximum).');
            }
            $collection->setName($name);
        }
        if (\array_key_exists('isPublic', $payload)) {
            $collection->setPublic((bool) $payload['isPublic']);
        }
        $this->em->flush();

        return new JsonResponse($this->summary($collection, 0, [], false));
    }

    #[Route('/api/collections/{id<\d+>}', name: 'api_collection_delete', methods: ['DELETE'])]
    public function delete(DishCollection $collection, #[CurrentUser] User $user): Response
    {
        $this->assertOwner($collection, $user);
        $this->em->remove($collection);
        $this->em->flush();

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    /**
     * A collection with its dishes (only those the viewer may see).
     */
    #[Route('/api/collections/{id<\d+>}', name: 'api_collection_show', methods: ['GET'])]
    public function show(DishCollection $collection, #[CurrentUser] User $viewer): JsonResponse
    {
        $isOwner = $collection->getOwner()->getId() === $viewer->getId();
        if (!$isOwner && !$collection->isPublic()) {
            throw new NotFoundHttpException('Collection introuvable.');
        }

        $qb = $this->em->createQueryBuilder()
            ->select('d', 'a')
            ->from(Dish::class, 'd')
            ->join('d.author', 'a')
            ->join(DishCollectionItem::class, 'i', 'WITH', 'i.dish = d')
            ->where('i.collection = :collection')
            ->setParameter('collection', $collection)
            ->orderBy('i.id', 'DESC')
            ->setMaxResults(100);
        $this->access->restrict($qb, 'd', $viewer);
        /** @var list<Dish> $dishes */
        $dishes = $qb->getQuery()->getResult();

        $owner = $collection->getOwner();

        return new JsonResponse([
            'id' => $collection->getId(),
            'name' => $collection->getName(),
            'isPublic' => $collection->isPublic(),
            'isMine' => $isOwner,
            'owner' => ['id' => $owner->getId(), 'displayName' => $owner->getDisplayName(), 'avatarUrl' => $owner->getAvatarUrl()],
            'dishes' => $this->normalizer->normalize($dishes, 'json', ['groups' => ['dish:read']]),
        ]);
    }

    #[Route('/api/collections/{id<\d+>}/dishes/{dishId<\d+>}', name: 'api_collection_add_dish', methods: ['POST'])]
    public function addDish(DishCollection $collection, int $dishId, #[CurrentUser] User $user): JsonResponse
    {
        $this->assertOwner($collection, $user);
        $dish = $this->em->find(Dish::class, $dishId) ?? throw new NotFoundHttpException('Met introuvable.');
        $this->access->assertCanView($dish, $user);

        $isNew = null === $this->items->findOneBy(['collection' => $collection, 'dish' => $dish]);
        if ($isNew) {
            $this->em->persist(new DishCollectionItem($collection, $dish));
            $this->em->flush();
            if ($collection->isPublic() && $dish->isPublic() && null !== $dish->getAuthor()) {
                $this->notifications->record($dish->getAuthor(), NotificationType::Collection, $user, $dish, $collection->getName());
            }
        }

        return new JsonResponse(['added' => true]);
    }

    #[Route('/api/collections/{id<\d+>}/dishes/{dishId<\d+>}', name: 'api_collection_remove_dish', methods: ['DELETE'])]
    public function removeDish(DishCollection $collection, int $dishId, #[CurrentUser] User $user): JsonResponse
    {
        $this->assertOwner($collection, $user);
        $item = $this->items->findOneBy(['collection' => $collection, 'dish' => $dishId]);
        if (null !== $item) {
            $this->em->remove($item);
            $this->em->flush();
        }

        return new JsonResponse(['added' => false]);
    }

    private function assertOwner(DishCollection $collection, User $user): void
    {
        if ($collection->getOwner()->getId() !== $user->getId()) {
            throw new NotFoundHttpException('Collection introuvable.');
        }
    }

    private function cleanName(mixed $name): ?string
    {
        $name = \is_string($name) ? trim($name) : '';

        return '' === $name || mb_strlen($name) > 80 ? null : $name;
    }

    private function error(string $detail): JsonResponse
    {
        return new JsonResponse(['title' => 'Collection invalide', 'detail' => $detail, 'status' => 422], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * @param list<array{id: int, photoUrl: ?string}> $covers
     *
     * @return array<string, mixed>
     */
    private function summary(DishCollection $c, int $count, array $covers, ?bool $contains): array
    {
        return ['id' => $c->getId(), 'name' => $c->getName(), 'isPublic' => $c->isPublic(), 'dishCount' => $count, 'covers' => $covers, 'containsDish' => $contains];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function summaries(User $owner, User $viewer, bool $publicOnly, ?int $containsDishId = null): array
    {
        $cond = $this->access->sqlCondition('d');
        $rows = $this->db->fetchAllAssociative(
            \sprintf(
                'SELECT c.id, c.name, c.public,
                    (SELECT COUNT(*) FROM dish_collection_item i JOIN dish d ON d.id = i.dish_id WHERE i.collection_id = c.id AND %s) AS cnt,
                    EXISTS (SELECT 1 FROM dish_collection_item x WHERE x.collection_id = c.id AND x.dish_id = :dish) AS has_dish
                 FROM dish_collection c
                 WHERE c.owner_id = :owner %s
                 ORDER BY c.created_at DESC, c.id DESC',
                $cond,
                $publicOnly ? 'AND c.public = TRUE' : '',
            ),
            ['owner' => $owner->getId(), 'viewer_id' => $viewer->getId(), 'dish' => $containsDishId ?? 0],
        );
        if ([] === $rows) {
            return [];
        }

        $covers = [];
        $coverRows = $this->db->fetchAllAssociative(
            \sprintf(
                'SELECT i.collection_id, d.id, d.photo_url FROM dish_collection_item i JOIN dish d ON d.id = i.dish_id
                 WHERE i.collection_id IN (:ids) AND %s ORDER BY i.id DESC',
                $cond,
            ),
            ['ids' => array_map(static fn (array $r): int => (int) $r['id'], $rows), 'viewer_id' => $viewer->getId()],
            ['ids' => ArrayParameterType::INTEGER],
        );
        foreach ($coverRows as $r) {
            $covers[(int) $r['collection_id']][] = ['id' => (int) $r['id'], 'photoUrl' => $r['photo_url']];
        }

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'name' => (string) $r['name'],
            'isPublic' => (bool) $r['public'],
            'dishCount' => (int) $r['cnt'],
            'covers' => \array_slice($covers[(int) $r['id']] ?? [], 0, 3),
            'containsDish' => null === $containsDishId ? null : (bool) $r['has_dish'],
        ], $rows);
    }
}

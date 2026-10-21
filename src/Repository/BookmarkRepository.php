<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Bookmark;
use App\Entity\Dish;
use App\Entity\User;
use App\Service\DishAccess;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Bookmark>
 */
class BookmarkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Bookmark::class);
    }

    /**
     * @return list<int>
     */
    public function bookmarkedDishIds(User $user): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT dish_id FROM bookmark WHERE user_id = :id ORDER BY created_at DESC, id DESC',
            ['id' => $user->getId()],
        );

        return array_map('intval', $rows);
    }

    /**
     * @return list<Dish>
     */
    public function findDishes(User $user, int $page, int $perPage, ?DishAccess $access = null, ?User $viewer = null): array
    {
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('d', 'a')
            ->from(Dish::class, 'd')
            ->join('d.author', 'a')
            ->join(Bookmark::class, 'b', 'WITH', 'b.dish = d')
            ->where('b.user = :user')
            ->setParameter('user', $user)
            ->orderBy('b.createdAt', 'DESC')
            ->addOrderBy('b.id', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);
        $access?->restrict($qb, 'd', $viewer ?? $user); // a dish hidden since it was bookmarked disappears

        /** @var list<Dish> $dishes */
        $dishes = $qb->getQuery()->getResult();

        return $dishes;
    }
}

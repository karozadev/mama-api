<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Dish;
use App\Entity\DishLike;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DishLike>
 */
class DishLikeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DishLike::class);
    }

    public function countFor(Dish $dish): int
    {
        return (int) $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->where('l.dish = :dish')
            ->setParameter('dish', $dish)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return list<int>
     */
    public function likedDishIds(User $user): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT dish_id FROM dish_like WHERE user_id = :id ORDER BY id DESC',
            ['id' => $user->getId()],
        );

        return array_map('intval', $rows);
    }
}

<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Dish;
use App\Entity\Review;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Review>
 */
class ReviewRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Review::class);
    }

    /**
     * @return array{average: float, count: int}
     */
    public function computeStats(Dish $dish): array
    {
        $row = $this->createQueryBuilder('r')
            ->select('AVG(r.rating) AS average', 'COUNT(r.id) AS total')
            ->where('r.dish = :dish')
            ->setParameter('dish', $dish)
            ->getQuery()
            ->getSingleResult();

        return ['average' => (float) $row['average'], 'count' => (int) $row['total']];
    }
}

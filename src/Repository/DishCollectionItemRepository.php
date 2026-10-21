<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DishCollectionItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DishCollectionItem>
 */
class DishCollectionItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DishCollectionItem::class);
    }
}

<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Notification;
use App\Entity\User;
use App\Enum\NotificationType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Notification>
 */
class NotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Notification::class);
    }

    /**
     * @param list<NotificationType>|null $types
     *
     * @return list<Notification>
     */
    public function findPage(User $user, int $page, int $perPage, ?array $types = null): array
    {
        $qb = $this->createQueryBuilder('n')
            ->addSelect('a', 'd')
            ->leftJoin('n.actor', 'a')
            ->leftJoin('n.dish', 'd')
            ->where('n.recipient = :user')
            ->setParameter('user', $user)
            ->orderBy('n.createdAt', 'DESC')
            ->addOrderBy('n.id', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);
        if (null !== $types) {
            $qb->andWhere('n.type IN (:types)')->setParameter('types', $types);
        }

        /** @var list<Notification> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    public function countUnread(User $user): int
    {
        return (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->where('n.recipient = :user')
            ->andWhere('n.readAt IS NULL')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function markAllRead(User $user): void
    {
        $this->createQueryBuilder('n')
            ->update()
            ->set('n.readAt', ':now')
            ->where('n.recipient = :user')
            ->andWhere('n.readAt IS NULL')
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }
}

<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Mothers followed by $user, most recently active first (feeds the "stories" row).
     *
     * @return list<array{id: int, displayName: string, avatarUrl: ?string, latestDishAt: ?string}>
     */
    public function findFollowedMothers(User $user, int $limit = 30): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT u.id, u.display_name, u.avatar_url, MAX(d.created_at) AS latest
             FROM user_follow f
             JOIN "user" u ON u.id = f.followed_id
             LEFT JOIN dish d ON d.author_id = u.id
             WHERE f.follower_id = :id AND u.banned_at IS NULL
             GROUP BY u.id
             ORDER BY latest DESC NULLS LAST, u.id
             LIMIT '.$limit,
            ['id' => $user->getId()],
        );

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'displayName' => (string) $r['display_name'],
            'avatarUrl' => $r['avatar_url'],
            'latestDishAt' => null === $r['latest'] ? null : (new \DateTimeImmutable((string) $r['latest']))->format(\DATE_ATOM),
        ], $rows);
    }

    /**
     * @return list<int>
     */
    public function findFollowerIds(User $mother): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT follower_id FROM user_follow WHERE followed_id = :id ORDER BY follower_id',
            ['id' => $mother->getId()],
        );

        return array_map('intval', $rows);
    }
}

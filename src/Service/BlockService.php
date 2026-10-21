<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Entity\UserBlock;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Blocking between two mothers: no messages, no follows, no notifications from the blocked one.
 */
final readonly class BlockService
{
    public function __construct(
        private EntityManagerInterface $em,
        private Connection $db,
    ) {
    }

    public function hasBlocked(User $blocker, User $blocked): bool
    {
        return false !== $this->db->fetchOne(
            'SELECT 1 FROM user_block WHERE blocker_id = :a AND blocked_id = :b',
            ['a' => $blocker->getId(), 'b' => $blocked->getId()],
        );
    }

    public function isBlockedEitherWay(User $a, User $b): bool
    {
        return false !== $this->db->fetchOne(
            'SELECT 1 FROM user_block WHERE (blocker_id = :a AND blocked_id = :b) OR (blocker_id = :b AND blocked_id = :a)',
            ['a' => $a->getId(), 'b' => $b->getId()],
        );
    }

    /**
     * Blocks $blocked and removes the follow relation in both directions. Idempotent.
     */
    public function block(User $blocker, User $blocked): void
    {
        if (!$this->hasBlocked($blocker, $blocked)) {
            $this->em->persist(new UserBlock($blocker, $blocked));
        }
        $blocker->unfollow($blocked);
        $blocked->unfollow($blocker);
        $this->em->flush();
    }

    public function unblock(User $blocker, User $blocked): void
    {
        $this->db->executeStatement(
            'DELETE FROM user_block WHERE blocker_id = :a AND blocked_id = :b',
            ['a' => $blocker->getId(), 'b' => $blocked->getId()],
        );
    }

    /**
     * @return list<array{id: int, displayName: string, avatarUrl: ?string}>
     */
    public function blockedBy(User $blocker): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT u.id, u.display_name, u.avatar_url FROM user_block b JOIN "user" u ON u.id = b.blocked_id
             WHERE b.blocker_id = :me ORDER BY b.id DESC',
            ['me' => $blocker->getId()],
        );

        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'displayName' => (string) $r['display_name'], 'avatarUrl' => $r['avatar_url']], $rows);
    }
}

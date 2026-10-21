<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DirectMessage;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DirectMessage>
 */
class DirectMessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DirectMessage::class);
    }

    /** Unread messages, ignoring those sent by mothers I blocked. */
    public function countUnreadFor(User $me): int
    {
        return (int) $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM direct_message m WHERE m.recipient_id = :me AND m.read_at IS NULL
             AND NOT EXISTS (SELECT 1 FROM user_block b WHERE b.blocker_id = :me AND b.blocked_id = m.sender_id)',
            ['me' => $me->getId()],
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Message;

/**
 * One batch of followers to notify about a daily special.
 */
final readonly class NotifyFollowersBatch
{
    /**
     * @param list<int> $followerIds
     */
    public function __construct(
        public int $dishId,
        public array $followerIds,
    ) {
    }
}

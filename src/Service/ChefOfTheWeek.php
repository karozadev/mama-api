<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * The mother whose public dishes received the most hearts over the last 7 days (cached for an hour).
 */
final readonly class ChefOfTheWeek
{
    private const MIN_LIKES = 3;

    public function __construct(
        private Connection $db,
        private CacheInterface $cache,
    ) {
    }

    /**
     * @return array{id: int, displayName: string, avatarUrl: ?string, city: ?string, likes: int}|null
     */
    public function get(): ?array
    {
        return $this->cache->get('chef_of_the_week', function (ItemInterface $item): ?array {
            $item->expiresAfter(3600);

            $row = $this->db->fetchAssociative(
                "SELECT u.id, u.display_name, u.avatar_url, u.city, COUNT(l.id) AS likes
                 FROM dish_like l
                 JOIN dish d ON d.id = l.dish_id AND d.visibility = 'public'
                 JOIN \"user\" u ON u.id = d.author_id
                 WHERE l.created_at >= :since AND u.banned_at IS NULL
                 GROUP BY u.id
                 HAVING COUNT(l.id) >= :min
                 ORDER BY likes DESC, u.id
                 LIMIT 1",
                ['since' => (new \DateTimeImmutable('-7 days'))->format('Y-m-d H:i:s'), 'min' => self::MIN_LIKES],
            );

            return false === $row ? null : [
                'id' => (int) $row['id'],
                'displayName' => (string) $row['display_name'],
                'avatarUrl' => $row['avatar_url'],
                'city' => $row['city'],
                'likes' => (int) $row['likes'],
            ];
        });
    }
}

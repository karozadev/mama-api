<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * "Top 10 du jour": public dishes published today, ranked by hearts, then reviews, then rating
 * (the same order the app asks the API for).
 */
final readonly class DishRanking
{
    public function __construct(
        private Connection $db,
        private CacheInterface $cache,
    ) {
    }

    public function startOfToday(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('today');
    }

    /**
     * @return list<array{id: int, title: string, authorId: int}> best first
     */
    public function topToday(int $limit = 10): array
    {
        return $this->cache->get(\sprintf('top_today_%d_%s', $limit, $this->startOfToday()->format('Ymd')), function (ItemInterface $item) use ($limit): array {
            $item->expiresAfter(60);

            return $this->query($this->startOfToday(), $limit);
        });
    }

    /**
     * The best dish of today, or - when nothing was published yet today - the most hearted dish ever.
     *
     * @return array{id: int, title: string, authorId: int}|null
     */
    public function headline(): ?array
    {
        return $this->topToday(1)[0] ?? ($this->query(null, 1)[0] ?? null);
    }

    /**
     * @return int|null rank (1 = best) when the dish is in today's top 10
     */
    public function rankOf(int $dishId): ?int
    {
        foreach ($this->topToday(10) as $i => $row) {
            if ($row['id'] === $dishId) {
                return $i + 1;
            }
        }

        return null;
    }

    /**
     * The best rank reached by one of the mother's dishes today (within the top 10).
     *
     * @return array{dishId: int, title: string, rank: int}|null
     */
    public function bestOfAuthor(int $authorId): ?array
    {
        foreach ($this->topToday(10) as $i => $row) {
            if ($row['authorId'] === $authorId) {
                return ['dishId' => $row['id'], 'title' => $row['title'], 'rank' => $i + 1];
            }
        }

        return null;
    }

    /**
     * @return list<array{id: int, title: string, authorId: int}>
     */
    private function query(?\DateTimeImmutable $since, int $limit): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT d.id, d.title, d.author_id FROM dish d
             JOIN "user" u ON u.id = d.author_id AND u.banned_at IS NULL
             WHERE d.visibility = \'public\' AND d.hidden_by_moderation = FALSE
               AND (CAST(:since AS timestamp) IS NULL OR d.created_at >= CAST(:since AS timestamp))
               AND d.like_count > 0
             ORDER BY d.like_count DESC, d.review_count DESC, d.average_rating DESC, d.id ASC
             LIMIT '.max(1, min($limit, 50)),
            ['since' => $since?->format('Y-m-d H:i:s')],
        );

        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'title' => (string) $r['title'], 'authorId' => (int) $r['author_id']], $rows);
    }
}

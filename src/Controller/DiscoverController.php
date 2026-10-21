<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\DishRepository;
use App\Service\ChefOfTheWeek;
use App\Service\DishAccess;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

#[IsGranted('ROLE_USER')]
final class DiscoverController
{
    public function __construct(
        private readonly Connection $db,
        private readonly DishRepository $dishes,
        private readonly NormalizerInterface $normalizer,
        private readonly DishAccess $access,
        private readonly ChefOfTheWeek $chefOfTheWeek,
    ) {
    }

    /**
     * "Nouveaux talents": recently joined mothers who already published, that the user does not follow yet.
     */
    #[Route('/api/discover/mothers', name: 'api_discover_mothers', methods: ['GET'])]
    public function newMothers(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $limit = max(1, min(50, $request->query->getInt('limit', 10)));
        $rows = $this->db->fetchAllAssociative(
            'SELECT u.id, u.display_name, u.avatar_url, u.city, COUNT(d.id) AS dish_count
             FROM "user" u
             JOIN dish d ON d.author_id = u.id AND d.visibility = \'public\'
             WHERE u.id <> :me AND u.banned_at IS NULL
               AND u.id NOT IN (SELECT followed_id FROM user_follow WHERE follower_id = :me)
             GROUP BY u.id
             ORDER BY u.created_at DESC, u.id DESC
             LIMIT '.$limit,
            ['me' => $user->getId()],
        );

        $specialties = $this->specialties(array_map(static fn (array $r): int => (int) $r['id'], $rows));

        return new JsonResponse(array_map(fn (array $r): array => $this->mother($r) + ['specialty' => $specialties[(int) $r['id']] ?? null], $rows));
    }

    /**
     * Hearts received over the last 24 h compared with the 24 h before: the "+32 %" shown next to the trends.
     */
    #[Route('/api/discover/trend', name: 'api_discover_trend', methods: ['GET'])]
    public function trend(): JsonResponse
    {
        $now = new \DateTimeImmutable();
        $count = fn (\DateTimeImmutable $from, \DateTimeImmutable $to): int => (int) $this->db->fetchOne(
            'SELECT COUNT(l.id) FROM dish_like l JOIN dish d ON d.id = l.dish_id AND d.visibility = \'public\' AND d.hidden_by_moderation = FALSE
             WHERE l.created_at >= :from AND l.created_at < :to',
            ['from' => $from->format('Y-m-d H:i:s'), 'to' => $to->format('Y-m-d H:i:s')],
        );
        // Upper bound slightly in the future: a heart given within the current second still counts.
        $current = $count($now->modify('-24 hours'), $now->modify('+1 minute'));
        $previous = $count($now->modify('-48 hours'), $now->modify('-24 hours'));

        return new JsonResponse(['likes' => $current, 'percent' => $previous > 0 ? (int) round(($current - $previous) / $previous * 100) : null]);
    }

    /**
     * "Spéc. poisson", "Végétarien", "Pâtisseries"…: what a mother mostly publishes.
     *
     * @param list<int> $ids
     *
     * @return array<int, string>
     */
    private function specialties(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }
        $totals = $this->db->fetchAllAssociative(
            'SELECT author_id, COUNT(*) AS total,
                    SUM(CASE WHEN vegetarian THEN 1 ELSE 0 END) AS veg,
                    SUM(CASE WHEN CAST(ingredients AS text) ~* \'(poisson|tilapia|crevette|thon|sardine|capitaine|langouste)\' THEN 1 ELSE 0 END) AS fish
             FROM dish WHERE author_id IN (:ids) AND visibility = \'public\' AND hidden_by_moderation = FALSE GROUP BY author_id',
            ['ids' => $ids],
            ['ids' => ArrayParameterType::INTEGER],
        );
        $categories = [];
        foreach ($this->db->fetchAllAssociative(
            'SELECT author_id, category, COUNT(*) AS c FROM dish WHERE author_id IN (:ids) AND visibility = \'public\' AND hidden_by_moderation = FALSE
             GROUP BY author_id, category ORDER BY c DESC',
            ['ids' => $ids],
            ['ids' => ArrayParameterType::INTEGER],
        ) as $row) {
            $categories[(int) $row['author_id']] ??= (string) $row['category'];
        }
        $labels = ['plat' => 'Plats', 'dessert' => 'Pâtisseries', 'boisson' => 'Boissons', 'encas' => 'En-cas', 'entree' => 'Entrées', 'accompagnement' => 'Accompagnements'];

        $out = [];
        foreach ($totals as $row) {
            $id = (int) $row['author_id'];
            $total = max(1, (int) $row['total']);
            $out[$id] = match (true) {
                (int) $row['fish'] / $total >= 0.4 => 'Spéc. poisson',
                (int) $row['veg'] / $total >= 0.6 => 'Végétarien',
                default => $labels[$categories[$id] ?? 'plat'] ?? 'Plats',
            };
        }

        return $out;
    }

    #[Route('/api/discover/chef-of-the-week', name: 'api_discover_chef', methods: ['GET'])]
    public function chefOfTheWeek(): JsonResponse
    {
        $chef = $this->chefOfTheWeek->get();

        return null === $chef ? new JsonResponse('null', json: true) : new JsonResponse($chef);
    }

    /**
     * Search by dish name, description or ingredient, and by mother's name.
     */
    #[Route('/api/search', name: 'api_search', methods: ['GET'])]
    public function search(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $q = trim((string) $request->query->get('q', ''));
        if (mb_strlen($q) < 2) {
            return new JsonResponse(['dishes' => [], 'mothers' => []]);
        }
        $like = '%'.addcslashes(mb_substr($q, 0, 60), '\\%_').'%';

        $dishIds = array_map('intval', $this->db->fetchFirstColumn(
            'SELECT d.id FROM dish d
             WHERE (d.title ILIKE :q OR d.description ILIKE :q OR CAST(d.ingredients AS text) ILIKE :q)
               AND '.$this->access->sqlCondition('d').'
             ORDER BY d.like_count DESC, d.id DESC
             LIMIT 30',
            ['q' => $like, 'viewer_id' => $user->getId()],
        ));
        $dishes = [];
        if ([] !== $dishIds) {
            $byId = [];
            foreach ($this->dishes->findBy(['id' => $dishIds]) as $dish) {
                $byId[$dish->getId()] = $dish;
            }
            $dishes = array_values(array_filter(array_map(static fn (int $id) => $byId[$id] ?? null, $dishIds)));
        }

        $mothers = $this->db->fetchAllAssociative(
            'SELECT u.id, u.display_name, u.avatar_url, u.city, COUNT(d.id) AS dish_count
             FROM "user" u
             LEFT JOIN dish d ON d.author_id = u.id AND d.visibility = \'public\'
             WHERE u.display_name ILIKE :q AND u.banned_at IS NULL
             GROUP BY u.id
             ORDER BY dish_count DESC, u.id
             LIMIT 10',
            ['q' => $like],
        );

        return new JsonResponse([
            'dishes' => $this->normalizer->normalize($dishes, 'json', ['groups' => ['dish:read']]),
            'mothers' => array_map($this->mother(...), $mothers),
        ]);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function mother(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'displayName' => (string) $row['display_name'],
            'avatarUrl' => $row['avatar_url'],
            'city' => $row['city'],
            'dishCount' => (int) $row['dish_count'],
        ];
    }
}

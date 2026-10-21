<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Dish;
use App\Entity\User;
use App\Enum\DishVisibility;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Single place that decides who may see a dish (public / followers only / private).
 */
final class DishAccess
{
    public function canView(Dish $dish, ?User $viewer): bool
    {
        $author = $dish->getAuthor();
        if ($dish->isHiddenByModeration() || (null !== $author && $author->isBanned())) {
            return false;
        }

        return match ($dish->getVisibility()) {
            DishVisibility::Public => true,
            DishVisibility::Followers => null !== $viewer && null !== $author && ($viewer->getId() === $author->getId() || $viewer->isFollowing($author)),
            DishVisibility::Private => null !== $viewer && null !== $author && $viewer->getId() === $author->getId(),
        };
    }

    /**
     * A hidden dish answers 404, like a missing one, so its existence is not disclosed.
     */
    public function assertCanView(Dish $dish, ?User $viewer): void
    {
        if (!$this->canView($dish, $viewer)) {
            throw new NotFoundHttpException('Met introuvable.');
        }
    }

    /**
     * Restricts a DQL query on dishes (reachable through $dishAlias) to what $viewer may see.
     * Uses the fixed aliases/parameters "vis_author" and "vis_viewer".
     */
    public function restrict(QueryBuilder $qb, string $dishAlias, ?User $viewer): void
    {
        $qb->innerJoin(\sprintf('%s.author', $dishAlias), 'vis_author')
            ->andWhere(\sprintf('%s.hiddenByModeration = false AND vis_author.bannedAt IS NULL', $dishAlias));

        if (null === $viewer) {
            $qb->andWhere(\sprintf("%s.visibility = 'public'", $dishAlias));

            return;
        }

        $qb->andWhere(\sprintf(
            "(%1\$s.visibility = 'public' OR vis_author = :vis_viewer OR (%1\$s.visibility = 'followers' AND :vis_viewer MEMBER OF vis_author.followers))",
            $dishAlias,
        ))->setParameter('vis_viewer', $viewer);
    }

    /**
     * SQL fragment for native queries on table "dish" aliased $alias; bind :viewer_id (null when anonymous).
     */
    public function sqlCondition(string $alias): string
    {
        return \sprintf(
            "(%1\$s.hidden_by_moderation = FALSE AND NOT EXISTS (SELECT 1 FROM \"user\" ba WHERE ba.id = %1\$s.author_id AND ba.banned_at IS NOT NULL)
              AND (%1\$s.visibility = 'public' OR %1\$s.author_id = :viewer_id OR (%1\$s.visibility = 'followers' AND %1\$s.author_id IN (SELECT followed_id FROM user_follow WHERE follower_id = :viewer_id))))",
            $alias,
        );
    }
}

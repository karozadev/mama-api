<?php

declare(strict_types=1);

namespace App\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\Dish;
use App\Entity\Review;
use App\Entity\User;
use App\Service\DishAccess;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Hides non-visible dishes (and the reviews of hidden dishes) from every API Platform read.
 */
final readonly class DishVisibilityExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    public function __construct(
        private Security $security,
        private DishAccess $access,
    ) {
    }

    public function applyToCollection(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?Operation $operation = null, array $context = []): void
    {
        $this->apply($queryBuilder, $resourceClass);
    }

    public function applyToItem(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, array $identifiers, ?Operation $operation = null, array $context = []): void
    {
        $this->apply($queryBuilder, $resourceClass);
    }

    private function apply(QueryBuilder $queryBuilder, string $resourceClass): void
    {
        $viewer = $this->security->getUser();
        $viewer = $viewer instanceof User ? $viewer : null;
        $root = $queryBuilder->getRootAliases()[0];

        if (User::class === $resourceClass) {
            // A banned mother's profile disappears (moderators still see it).
            if (!$this->security->isGranted('ROLE_ADMIN')) {
                $queryBuilder->andWhere(\sprintf('%s.bannedAt IS NULL', $root));
            }
        } elseif (Dish::class === $resourceClass) {
            $this->access->restrict($queryBuilder, $root, $viewer);
        } elseif (Review::class === $resourceClass) {
            $queryBuilder->innerJoin(\sprintf('%s.dish', $root), 'vis_dish');
            $this->access->restrict($queryBuilder, 'vis_dish', $viewer);
        }
    }
}

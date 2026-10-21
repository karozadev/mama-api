<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Enum\Feature;
use App\Enum\UserPlan;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Single entry point to gate premium features. While the app is free, every feature is open.
 */
final readonly class FeatureAccess
{
    public function __construct(
        #[Autowire('%app.paid_features_enabled%')]
        private bool $paidFeaturesEnabled,
    ) {
    }

    public function isPremium(User $user): bool
    {
        if (UserPlan::Premium !== $user->getPlan()) {
            return false;
        }

        $expiresAt = $user->getPlanExpiresAt();

        return null === $expiresAt || $expiresAt > new \DateTimeImmutable();
    }

    public function can(User $user, Feature $feature): bool
    {
        return !$this->paidFeaturesEnabled || $this->isPremium($user);
    }
}

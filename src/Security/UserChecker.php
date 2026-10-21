<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * A banned account can neither log in nor keep using a token issued before the ban.
 */
final class UserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if ($user instanceof User && $user->isBanned()) {
            throw new CustomUserMessageAccountStatusException('Ce compte a été suspendu pour non-respect des règles de la communauté.');
        }
    }

    public function checkPostAuth(UserInterface $user): void
    {
    }
}

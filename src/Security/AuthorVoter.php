<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\AuthoredInterface;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Only the author of a dish or review may edit or delete it.
 *
 * @extends Voter<string, AuthoredInterface>
 */
final class AuthorVoter extends Voter
{
    public const EDIT = 'AUTHOR_EDIT';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::EDIT === $attribute && $subject instanceof AuthoredInterface;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        return $user instanceof User && $subject->getAuthor()?->getId() === $user->getId();
    }
}

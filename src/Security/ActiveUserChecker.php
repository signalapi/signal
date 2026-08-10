<?php

namespace App\Security;

use App\Entity\AdminUser;
use App\Entity\User;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Enforces the `active` flag at login for both identity sets. Without this
 * the flag was decorative — a deactivated account could still sign in.
 */
class ActiveUserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if (($user instanceof User || $user instanceof AdminUser) && !$user->isActive()) {
            throw new CustomUserMessageAccountStatusException('This account is disabled.');
        }
    }

    public function checkPostAuth(UserInterface $user): void
    {
    }
}

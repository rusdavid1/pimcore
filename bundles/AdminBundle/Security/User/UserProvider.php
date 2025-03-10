<?php

/**
 * Pimcore
 *
 * This source file is available under two different licenses:
 * - GNU General Public License version 3 (GPLv3)
 * - Pimcore Commercial License (PCL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 *  @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 *  @license    http://www.pimcore.org/license     GPLv3 and PCL
 */

namespace Pimcore\Bundle\AdminBundle\Security\User;

use Pimcore\Model\User as PimcoreUser;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

class UserProvider implements UserProviderInterface
{
    /**
     * {@inheritdoc}
     */
    public function loadUserByUsername($username)
    {
        $pimcoreUser = PimcoreUser::getByName($username);

        if ($pimcoreUser) {
            return new User($pimcoreUser);
        }

        throw new UserNotFoundException(sprintf('User %s was not found', $username));
    }

    /**
     * {@inheritdoc}
     *
     * @param User $user
     */
    public function refreshUser(UserInterface $user)
    {
        if (!$user instanceof User) {
            // user is not supported - we only support pimcore users
            throw new UnsupportedUserException();
        }

        /** @var PimcoreUser $refreshedPimcoreUser */
        $refreshedPimcoreUser = PimcoreUser::getById($user->getId());

        return $this->buildUser($refreshedPimcoreUser);
    }

    /**
     * @param PimcoreUser $pimcoreUser
     *
     * @return User
     */
    protected function buildUser(PimcoreUser $pimcoreUser)
    {
        return new User($pimcoreUser);
    }

    /**
     * {@inheritdoc}
     */
    public function supportsClass($class)
    {
        return $class === User::class;
    }
}

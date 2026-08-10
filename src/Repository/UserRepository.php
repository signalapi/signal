<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function save(User $user, bool $flush = true): void
    {
        $this->getEntityManager()->persist($user);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Roles are a json column, which DQL cannot pattern-match — hence the cast
     * in plain SQL. The platform's own database is PostgreSQL.
     */
    public function hasSuperAdmin(): bool
    {
        $connection = $this->getEntityManager()->getConnection();
        $table = $connection->quoteIdentifier($this->getClassMetadata()->getTableName());

        return (bool) $connection->fetchOne(
            sprintf('SELECT 1 FROM %s WHERE CAST(roles AS TEXT) LIKE :role LIMIT 1', $table),
            ['role' => '%ROLE_SUPER_ADMIN%'],
        );
    }

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->flush();
    }
}

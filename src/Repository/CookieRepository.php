<?php

namespace App\Repository;

use App\Entity\Cookie;
use App\Entity\User;
use App\Entity\Workspace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Cookie>
 */
class CookieRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Cookie::class);
    }

    public function remove(Cookie $cookie, bool $flush = true): void
    {
        $this->getEntityManager()->remove($cookie);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * The jar is per (workspace, user); null user is the shared jar that
     * automated flow runs use.
     *
     * @return Cookie[]
     */
    public function findByWorkspace(Workspace $workspace, ?User $user = null): array
    {
        $qb = $this->createQueryBuilder('c')
            ->andWhere('c.workspace = :ws')
            ->setParameter('ws', $workspace->getId(), 'uuid')
            ->orderBy('c.domain', 'ASC')
            ->addOrderBy('c.name', 'ASC');
        $this->scopeUser($qb, $user);

        return $qb->getQuery()->getResult();
    }

    public function findOneMatch(Workspace $workspace, ?User $user, string $domain, string $path, string $name): ?Cookie
    {
        return $this->findOneBy([
            'workspace' => $workspace,
            'user' => $user,
            'domain' => $domain,
            'path' => $path,
            'name' => $name,
        ]);
    }

    public function clearWorkspace(Workspace $workspace, ?User $user = null): int
    {
        $qb = $this->createQueryBuilder('c')
            ->delete()
            ->andWhere('c.workspace = :ws')
            ->setParameter('ws', $workspace->getId(), 'uuid');
        $this->scopeUser($qb, $user);

        return $qb->getQuery()->execute();
    }

    private function scopeUser(\Doctrine\ORM\QueryBuilder $qb, ?User $user): void
    {
        if (null === $user) {
            $qb->andWhere('c.user IS NULL');
        } else {
            $qb->andWhere('c.user = :u')->setParameter('u', $user->getId(), 'uuid');
        }
    }
}

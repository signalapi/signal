<?php

namespace App\Repository;

use App\Entity\Cookie;
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

    /** @return Cookie[] */
    public function findByWorkspace(Workspace $workspace): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.workspace = :ws')
            ->setParameter('ws', $workspace->getId(), 'uuid')
            ->orderBy('c.domain', 'ASC')
            ->addOrderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneMatch(Workspace $workspace, string $domain, string $path, string $name): ?Cookie
    {
        return $this->findOneBy(['workspace' => $workspace, 'domain' => $domain, 'path' => $path, 'name' => $name]);
    }

    public function clearWorkspace(Workspace $workspace): int
    {
        return $this->createQueryBuilder('c')
            ->delete()
            ->andWhere('c.workspace = :ws')
            ->setParameter('ws', $workspace->getId(), 'uuid')
            ->getQuery()
            ->execute();
    }
}

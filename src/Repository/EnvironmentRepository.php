<?php

namespace App\Repository;

use App\Entity\Environment;
use App\Entity\Workspace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Environment>
 */
class EnvironmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Environment::class);
    }

    public function save(Environment $environment, bool $flush = true): void
    {
        $this->getEntityManager()->persist($environment);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Environment $environment, bool $flush = true): void
    {
        $this->getEntityManager()->remove($environment);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** @return Environment[] */
    public function findByWorkspace(Workspace $workspace): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.workspace = :ws')
            ->setParameter('ws', $workspace->getId(), 'uuid')
            ->orderBy('e.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}

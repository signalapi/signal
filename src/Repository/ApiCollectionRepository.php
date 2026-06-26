<?php

namespace App\Repository;

use App\Entity\ApiCollection;
use App\Entity\Workspace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ApiCollection>
 */
class ApiCollectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ApiCollection::class);
    }

    public function save(ApiCollection $collection, bool $flush = true): void
    {
        $this->getEntityManager()->persist($collection);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(ApiCollection $collection, bool $flush = true): void
    {
        $this->getEntityManager()->remove($collection);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** @return ApiCollection[] */
    public function findByWorkspace(Workspace $workspace): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.workspace = :ws')
            ->setParameter('ws', $workspace->getId(), 'uuid')
            ->orderBy('c.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}

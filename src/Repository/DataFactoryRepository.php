<?php

namespace App\Repository;

use App\Entity\DataFactory;
use App\Entity\Workspace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DataFactory>
 */
class DataFactoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DataFactory::class);
    }

    public function save(DataFactory $factory, bool $flush = true): void
    {
        $this->getEntityManager()->persist($factory);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(DataFactory $factory, bool $flush = true): void
    {
        $this->getEntityManager()->remove($factory);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** @return DataFactory[] */
    public function findByWorkspace(Workspace $workspace): array
    {
        return $this->createQueryBuilder('f')
            ->andWhere('f.workspace = :ws')
            ->setParameter('ws', $workspace->getId(), 'uuid')
            ->orderBy('f.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}

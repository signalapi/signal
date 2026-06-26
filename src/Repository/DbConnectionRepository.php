<?php

namespace App\Repository;

use App\Entity\DbConnection;
use App\Entity\Workspace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DbConnection>
 */
class DbConnectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DbConnection::class);
    }

    public function save(DbConnection $connection, bool $flush = true): void
    {
        $this->getEntityManager()->persist($connection);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(DbConnection $connection, bool $flush = true): void
    {
        $this->getEntityManager()->remove($connection);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** @return DbConnection[] */
    public function findByWorkspace(Workspace $workspace): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.workspace = :ws')
            ->setParameter('ws', $workspace->getId(), 'uuid')
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}

<?php

namespace App\Repository;

use App\Entity\TestFlow;
use App\Entity\Workspace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TestFlow>
 */
class TestFlowRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TestFlow::class);
    }

    public function save(TestFlow $flow, bool $flush = true): void
    {
        $this->getEntityManager()->persist($flow);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(TestFlow $flow, bool $flush = true): void
    {
        $this->getEntityManager()->remove($flow);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** @return TestFlow[] */
    public function findScheduled(): array
    {
        return $this->createQueryBuilder('f')
            ->andWhere('f.scheduleEnabled = true')
            ->andWhere('f.cronExpression IS NOT NULL')
            ->getQuery()
            ->getResult();
    }

    /** @return TestFlow[] */
    public function findByWorkspace(Workspace $workspace): array
    {
        return $this->createQueryBuilder('f')
            ->andWhere('f.workspace = :ws')
            ->setParameter('ws', $workspace->getId(), 'uuid')
            ->orderBy('f.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}

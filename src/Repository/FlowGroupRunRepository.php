<?php

namespace App\Repository;

use App\Entity\FlowGroup;
use App\Entity\FlowGroupRun;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FlowGroupRun>
 */
class FlowGroupRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FlowGroupRun::class);
    }

    public function save(FlowGroupRun $run, bool $flush = true): void
    {
        $this->getEntityManager()->persist($run);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findOneByBatch(string $batchId): ?FlowGroupRun
    {
        return $this->findOneBy(['batchId' => $batchId]);
    }

    /** @return FlowGroupRun[] */
    public function recentForWorkspace(\App\Entity\Workspace $workspace, int $limit = 60): array
    {
        return $this->createQueryBuilder('gr')
            ->join('gr.flowGroup', 'g')
            ->andWhere('g.workspace = :ws')
            ->setParameter('ws', $workspace->getId(), 'uuid')
            ->orderBy('gr.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** @return FlowGroupRun[] */
    public function recentForGroup(FlowGroup $group, int $limit = 6): array
    {
        return $this->createQueryBuilder('gr')
            ->andWhere('gr.flowGroup = :g')
            ->setParameter('g', $group->getId(), 'uuid')
            ->orderBy('gr.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}

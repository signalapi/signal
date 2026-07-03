<?php

namespace App\Repository;

use App\Entity\FlowRun;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FlowRun>
 */
class FlowRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FlowRun::class);
    }

    public function save(FlowRun $run, bool $flush = true): void
    {
        $this->getEntityManager()->persist($run);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** @return FlowRun[] */
    public function findByBatch(string $batchId): array
    {
        return $this->findBy(['batchId' => $batchId], ['iteration' => 'ASC']);
    }

    /**
     * @return FlowRun[] most recent first
     */
    public function recentForFlow(\App\Entity\TestFlow $flow, int $limit = 30): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.flow = :f')
            ->setParameter('f', $flow->getId(), 'uuid')
            ->orderBy('r.createdAt', 'DESC')
            // Tiebreak by id (UUIDv7 = time-ordered) so two runs in the same
            // second still order deterministically — "latest" stays correct.
            ->addOrderBy('r.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return FlowRun[] most recent runs across all flows in a workspace
     */
    public function recentForWorkspace(\App\Entity\Workspace $workspace, int $limit = 6): array
    {
        return $this->createQueryBuilder('r')
            ->join('r.flow', 'f')
            ->andWhere('f.workspace = :ws')
            ->setParameter('ws', $workspace->getId(), 'uuid')
            ->orderBy('r.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Most recent standalone runs (NOT part of a suite) in a workspace — for the
     * history view, where suite runs are shown as a single suite entry instead.
     *
     * @return FlowRun[]
     */
    public function recentStandaloneForWorkspace(\App\Entity\Workspace $workspace, int $limit = 100): array
    {
        return $this->createQueryBuilder('r')
            ->join('r.flow', 'f')
            ->andWhere('f.workspace = :ws')
            ->andWhere('r.trigger != :grp')
            ->setParameter('ws', $workspace->getId(), 'uuid')
            ->setParameter('grp', 'group')
            ->orderBy('r.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Recent group (suite) runs for a flow group, one row per batch.
     *
     * @return array<int, array{batchId: string, started: \DateTimeInterface, total: int, passed: int}>
     */
    public function recentGroupBatches(\App\Entity\FlowGroup $group, int $limit = 5): array
    {
        /** @var array<int, array<string, mixed>> $rows */
        $rows = $this->createQueryBuilder('r')
            ->select('r.batchId AS batchId, MIN(r.createdAt) AS started, COUNT(r.id) AS total, SUM(CASE WHEN r.status = :passed THEN 1 ELSE 0 END) AS passed')
            ->join('r.flow', 'f')
            ->join(\App\Entity\FlowGroupItem::class, 'i', 'WITH', 'i.flow = f')
            ->andWhere('i.flowGroup = :g')
            ->andWhere('r.trigger = :trg')
            ->andWhere('r.batchId IS NOT NULL')
            ->setParameter('g', $group->getId(), 'uuid')
            ->setParameter('trg', 'group')
            ->setParameter('passed', \App\Entity\FlowRun::STATUS_PASSED)
            ->groupBy('r.batchId')
            ->orderBy('started', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $r): array => [
            'batchId' => (string) $r['batchId'],
            'started' => $r['started'] instanceof \DateTimeInterface ? $r['started'] : new \DateTimeImmutable((string) $r['started']),
            'total' => (int) $r['total'],
            'passed' => (int) $r['passed'],
        ], $rows);
    }

    public function countForFlow(\App\Entity\TestFlow $flow): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.flow = :f')
            ->setParameter('f', $flow->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();
    }
}

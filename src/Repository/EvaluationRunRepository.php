<?php

namespace App\Repository;

use App\Entity\Evaluation;
use App\Entity\EvaluationRun;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EvaluationRun>
 */
class EvaluationRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EvaluationRun::class);
    }

    public function save(EvaluationRun $run, bool $flush = true): void
    {
        $this->getEntityManager()->persist($run);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Most recent first — the detail page and the "last result" badge.
     *
     * @return EvaluationRun[]
     */
    public function recentFor(Evaluation $evaluation, int $limit = 30): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.evaluation = :e')
            ->setParameter('e', $evaluation->getId(), 'uuid')
            ->orderBy('r.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Oldest first, finished runs only — the shape a trend is drawn from. Reads
     * the denormalised columns, so ninety days of history is one query and no
     * step result is loaded.
     *
     * @return EvaluationRun[]
     */
    public function trendFor(Evaluation $evaluation, int $limit = 90): array
    {
        $runs = $this->createQueryBuilder('r')
            ->andWhere('r.evaluation = :e')
            ->andWhere('r.status = :done')
            ->setParameter('e', $evaluation->getId(), 'uuid')
            ->setParameter('done', EvaluationRun::STATUS_DONE)
            ->orderBy('r.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return array_reverse($runs);
    }

    public function latestFor(Evaluation $evaluation): ?EvaluationRun
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.evaluation = :e')
            ->setParameter('e', $evaluation->getId(), 'uuid')
            ->orderBy('r.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}

<?php

namespace App\Repository;

use App\Entity\Evaluation;
use App\Entity\TestFlow;
use App\Entity\Workspace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Evaluation>
 */
class EvaluationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Evaluation::class);
    }

    public function save(Evaluation $evaluation, bool $flush = true): void
    {
        $this->getEntityManager()->persist($evaluation);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Evaluation $evaluation, bool $flush = true): void
    {
        $this->getEntityManager()->remove($evaluation);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** @return Evaluation[] */
    public function findByWorkspace(Workspace $workspace): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.workspace = :ws')
            ->setParameter('ws', $workspace->getId(), 'uuid')
            ->orderBy('e.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return Evaluation[] */
    public function findByFlow(TestFlow $flow): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.flow = :f')
            ->setParameter('f', $flow->getId(), 'uuid')
            ->orderBy('e.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}

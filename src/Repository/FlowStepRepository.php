<?php

namespace App\Repository;

use App\Entity\FlowStep;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FlowStep>
 */
class FlowStepRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FlowStep::class);
    }

    public function save(FlowStep $step, bool $flush = true): void
    {
        $this->getEntityManager()->persist($step);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(FlowStep $step, bool $flush = true): void
    {
        $this->getEntityManager()->remove($step);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}

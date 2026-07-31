<?php

namespace App\Repository;

use App\Entity\Schedule;
use App\Entity\Workspace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Schedule>
 */
class ScheduleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Schedule::class);
    }

    public function save(Schedule $schedule, bool $flush = true): void
    {
        $this->getEntityManager()->persist($schedule);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Schedule $schedule, bool $flush = true): void
    {
        $this->getEntityManager()->remove($schedule);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** @return list<Schedule> */
    public function findByWorkspace(Workspace $workspace): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.workspace = :ws')->setParameter('ws', $workspace)
            ->orderBy('s.enabled', 'DESC')
            ->addOrderBy('s.name', 'ASC')
            ->getQuery()->getResult();
    }

    /** Everything the scheduler tick has to consider. @return list<Schedule> */
    public function findEnabled(): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.enabled = true')
            ->getQuery()->getResult();
    }

    /** @return list<Schedule> schedules pointed at this flow */
    public function findForFlow(\App\Entity\TestFlow $flow): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.flow = :f')->setParameter('f', $flow)
            ->getQuery()->getResult();
    }
}

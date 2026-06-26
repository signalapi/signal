<?php

namespace App\Repository;

use App\Entity\FlowGroup;
use App\Entity\Workspace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FlowGroup>
 */
class FlowGroupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FlowGroup::class);
    }

    public function save(FlowGroup $group, bool $flush = true): void
    {
        $this->getEntityManager()->persist($group);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(FlowGroup $group, bool $flush = true): void
    {
        $this->getEntityManager()->remove($group);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** @return FlowGroup[] */
    public function findByWorkspace(Workspace $workspace): array
    {
        return $this->createQueryBuilder('g')
            ->andWhere('g.workspace = :ws')
            ->setParameter('ws', $workspace->getId(), 'uuid')
            ->orderBy('g.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}

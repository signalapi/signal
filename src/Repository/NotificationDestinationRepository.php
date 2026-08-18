<?php

namespace App\Repository;

use App\Entity\NotificationDestination;
use App\Entity\Workspace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NotificationDestination>
 */
class NotificationDestinationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NotificationDestination::class);
    }

    public function save(NotificationDestination $destination, bool $flush = true): void
    {
        $this->getEntityManager()->persist($destination);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(NotificationDestination $destination, bool $flush = true): void
    {
        $this->getEntityManager()->remove($destination);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** @return NotificationDestination[] */
    public function findByWorkspace(Workspace $workspace): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.workspace = :ws')
            ->setParameter('ws', $workspace->getId(), 'uuid')
            ->orderBy('d.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Active destinations of the workspace whose ids are in the given list —
     * the guard that keeps a run override from reaching another workspace.
     *
     * @param list<string> $ids
     *
     * @return NotificationDestination[]
     */
    public function findActiveByWorkspaceAndIds(Workspace $workspace, array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        return $this->createQueryBuilder('d')
            ->andWhere('d.workspace = :ws')
            ->andWhere('d.active = true')
            ->andWhere('d.id IN (:ids)')
            ->setParameter('ws', $workspace->getId(), 'uuid')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();
    }
}

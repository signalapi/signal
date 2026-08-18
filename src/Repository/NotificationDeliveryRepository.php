<?php

namespace App\Repository;

use App\Entity\NotificationDelivery;
use App\Entity\Workspace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NotificationDelivery>
 */
class NotificationDeliveryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NotificationDelivery::class);
    }

    public function save(NotificationDelivery $delivery, bool $flush = true): void
    {
        $this->getEntityManager()->persist($delivery);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** @return NotificationDelivery[] */
    public function findRecentByWorkspace(Workspace $workspace, int $limit = 20): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.workspace = :ws')
            ->setParameter('ws', $workspace->getId(), 'uuid')
            ->orderBy('d.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}

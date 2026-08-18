<?php

namespace App\Repository;

use App\Entity\NotificationSubscription;
use App\Entity\Workspace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<NotificationSubscription>
 */
class NotificationSubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NotificationSubscription::class);
    }

    public function save(NotificationSubscription $subscription, bool $flush = true): void
    {
        $this->getEntityManager()->persist($subscription);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(NotificationSubscription $subscription, bool $flush = true): void
    {
        $this->getEntityManager()->remove($subscription);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** @return NotificationSubscription[] */
    public function findByWorkspace(Workspace $workspace): array
    {
        return $this->createQueryBuilder('s')
            ->addSelect('d')
            ->join('s.destination', 'd')
            ->andWhere('s.workspace = :ws')
            ->setParameter('ws', $workspace->getId(), 'uuid')
            ->orderBy('s.scopeType', 'ASC')
            ->addOrderBy('s.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Enabled rules that cover a run: the workspace-wide ones plus the ones
     * pinned to this flow or suite.
     *
     * @return NotificationSubscription[]
     */
    public function findMatching(Workspace $workspace, string $scopeType, ?Uuid $scopeId): array
    {
        $qb = $this->createQueryBuilder('s')
            ->addSelect('d')
            ->join('s.destination', 'd')
            ->andWhere('s.workspace = :ws')
            ->andWhere('s.enabled = true')
            ->andWhere('d.active = true')
            ->setParameter('ws', $workspace->getId(), 'uuid');

        if (null === $scopeId) {
            $qb->andWhere('s.scopeType = :wsScope')->setParameter('wsScope', NotificationSubscription::SCOPE_WORKSPACE);
        } else {
            $qb->andWhere('s.scopeType = :wsScope OR (s.scopeType = :scope AND s.scopeId = :scopeId)')
                ->setParameter('wsScope', NotificationSubscription::SCOPE_WORKSPACE)
                ->setParameter('scope', $scopeType)
                ->setParameter('scopeId', $scopeId, 'uuid');
        }

        return $qb->getQuery()->getResult();
    }
}

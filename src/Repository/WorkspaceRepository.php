<?php

namespace App\Repository;

use App\Entity\Merchant;
use App\Entity\Workspace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Workspace>
 */
class WorkspaceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Workspace::class);
    }

    public function save(Workspace $workspace, bool $flush = true): void
    {
        $this->getEntityManager()->persist($workspace);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Workspace $workspace, bool $flush = true): void
    {
        $this->getEntityManager()->remove($workspace);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * @return Workspace[]
     */
    public function findByMerchant(Merchant $merchant): array
    {
        return $this->createQueryBuilder('w')
            ->andWhere('w.merchant = :merchant')
            ->setParameter('merchant', $merchant->getId(), 'uuid')
            ->orderBy('w.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}

<?php

namespace App\Repository;

use App\Entity\CatalogApi;
use App\Entity\Merchant;
use App\Entity\Workspace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CatalogApi>
 */
class CatalogApiRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CatalogApi::class);
    }

    /**
     * Marketplace listing for one viewer: every published public entry, plus
     * the private entries of the companies they belong to and the workspaces
     * they can see.
     *
     * @param Merchant[]  $merchants  the viewer's company memberships
     * @param Workspace[] $workspaces workspaces the viewer may view
     *
     * @return CatalogApi[]
     */
    public function findVisible(array $merchants = [], array $workspaces = []): array
    {
        $qb = $this->createQueryBuilder('c')
            ->distinct()
            ->join('c.versions', 'v')
            ->andWhere('c.active = true');

        $where = $qb->expr()->orX('c.visibility = :public');
        $qb->setParameter('public', CatalogApi::VISIBILITY_PUBLIC);

        if ([] !== $merchants) {
            $where->add($qb->expr()->andX('c.visibility = :vMerchant', 'c.ownerMerchant IN (:merchants)'));
            $qb->setParameter('vMerchant', CatalogApi::VISIBILITY_MERCHANT)
                ->setParameter('merchants', $merchants);
        }

        if ([] !== $workspaces) {
            $where->add($qb->expr()->andX('c.visibility = :vWorkspace', 'c.ownerWorkspace IN (:workspaces)'));
            $qb->setParameter('vWorkspace', CatalogApi::VISIBILITY_WORKSPACE)
                ->setParameter('workspaces', $workspaces);
        }

        return $qb->andWhere($where)
            ->orderBy('c.verified', 'DESC')
            ->addOrderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Entries published by a company — what its admins manage.
     *
     * @return CatalogApi[]
     */
    public function findByOwnerMerchant(Merchant $merchant): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.ownerMerchant = :m')
            ->setParameter('m', $merchant->getId(), 'uuid')
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}

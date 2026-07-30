<?php

namespace App\Repository;

use App\Entity\CatalogApi;
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

    /** Marketplace listing: active entries that have at least one published version. */
    /** @return CatalogApi[] */
    public function findPublished(): array
    {
        return $this->createQueryBuilder('c')
            ->distinct()
            ->join('c.versions', 'v')
            ->andWhere('c.active = true')
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}

<?php

namespace App\Repository;

use App\Entity\ApiRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ApiRequest>
 */
class ApiRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ApiRequest::class);
    }

    public function save(ApiRequest $request, bool $flush = true): void
    {
        $this->getEntityManager()->persist($request);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(ApiRequest $request, bool $flush = true): void
    {
        $this->getEntityManager()->remove($request);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * All requests belonging to a workspace (across its collections), grouped-friendly.
     *
     * @return ApiRequest[]
     */
    public function findByWorkspace(\App\Entity\Workspace $workspace): array
    {
        return $this->createQueryBuilder('r')
            ->join('r.collection', 'c')
            ->andWhere('c.workspace = :ws')
            ->setParameter('ws', $workspace->getId(), 'uuid')
            ->orderBy('c.name', 'ASC')
            ->addOrderBy('r.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}

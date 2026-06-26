<?php

namespace App\Repository;

use App\Entity\ApiRequest;
use App\Entity\ResponseExample;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ResponseExample>
 */
class ResponseExampleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ResponseExample::class);
    }

    public function save(ResponseExample $example, bool $flush = true): void
    {
        $this->getEntityManager()->persist($example);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(ResponseExample $example, bool $flush = true): void
    {
        $this->getEntityManager()->remove($example);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** @return ResponseExample[] */
    public function findForRequest(ApiRequest $request): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.apiRequest = :r')
            ->setParameter('r', $request->getId(), 'uuid')
            ->orderBy('e.position', 'ASC')
            ->addOrderBy('e.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneForRequestByStatus(ApiRequest $request, ?int $statusCode): ?ResponseExample
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.apiRequest = :r')
            ->andWhere('e.statusCode = :s')
            ->setParameter('r', $request->getId(), 'uuid')
            ->setParameter('s', $statusCode)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function nextPosition(ApiRequest $request): int
    {
        $max = $this->createQueryBuilder('e')
            ->select('MAX(e.position)')
            ->andWhere('e.apiRequest = :r')
            ->setParameter('r', $request->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();

        return null === $max ? 0 : ((int) $max + 1);
    }
}

<?php

namespace App\Repository;

use App\Entity\ApiToken;
use App\Entity\Workspace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ApiToken>
 */
class ApiTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ApiToken::class);
    }

    public function save(ApiToken $token, bool $flush = true): void
    {
        $this->getEntityManager()->persist($token);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(ApiToken $token, bool $flush = true): void
    {
        $this->getEntityManager()->remove($token);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findActiveByHash(string $hash): ?ApiToken
    {
        return $this->findOneBy(['tokenHash' => $hash, 'revoked' => false]);
    }

    /** @return ApiToken[] */
    public function findByWorkspace(Workspace $workspace): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.workspace = :ws')
            ->setParameter('ws', $workspace->getId(), 'uuid')
            ->orderBy('t.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}

<?php

namespace App\Repository;

use App\Entity\McpServer;
use App\Entity\Workspace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<McpServer>
 */
class McpServerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, McpServer::class);
    }

    public function save(McpServer $server, bool $flush = true): void
    {
        $this->getEntityManager()->persist($server);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(McpServer $server, bool $flush = true): void
    {
        $this->getEntityManager()->remove($server);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** @return McpServer[] */
    public function findByWorkspace(Workspace $workspace): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.workspace = :ws')
            ->setParameter('ws', $workspace->getId(), 'uuid')
            ->orderBy('s.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByName(Workspace $workspace, string $name): ?McpServer
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.workspace = :ws')
            ->andWhere('LOWER(s.name) = :n')
            ->setParameter('ws', $workspace->getId(), 'uuid')
            ->setParameter('n', mb_strtolower(trim($name)))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}

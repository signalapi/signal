<?php

namespace App\Repository;

use App\Entity\MockRoute;
use App\Entity\Workspace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MockRoute>
 */
class MockRouteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MockRoute::class);
    }

    /**
     * @return MockRoute[]
     */
    public function findByWorkspace(Workspace $workspace): array
    {
        return $this->findBy(['workspace' => $workspace], ['createdAt' => 'ASC']);
    }

    /**
     * Active routes, exact paths first so a wildcard can never shadow one.
     *
     * @return MockRoute[]
     */
    public function findActiveByWorkspace(Workspace $workspace): array
    {
        $routes = $this->findBy(['workspace' => $workspace, 'active' => true]);
        usort($routes, static function (MockRoute $a, MockRoute $b): int {
            $aWild = str_ends_with($a->getPath(), '/*');
            $bWild = str_ends_with($b->getPath(), '/*');
            if ($aWild !== $bWild) {
                return $aWild <=> $bWild;
            }

            // Longer (more specific) wildcard prefixes win among themselves.
            return mb_strlen($b->getPath()) <=> mb_strlen($a->getPath());
        });

        return $routes;
    }

    public function save(MockRoute $route, bool $flush = true): void
    {
        $this->getEntityManager()->persist($route);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(MockRoute $route, bool $flush = true): void
    {
        $this->getEntityManager()->remove($route);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}

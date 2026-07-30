<?php

namespace App\Repository;

use App\Entity\Merchant;
use App\Entity\User;
use App\Entity\Workspace;
use App\Entity\WorkspaceMember;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WorkspaceMember>
 */
class WorkspaceMemberRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkspaceMember::class);
    }

    public function findOneByUserAndWorkspace(User $user, Workspace $workspace): ?WorkspaceMember
    {
        return $this->findOneBy(['user' => $user, 'workspace' => $workspace]);
    }

    /** @return WorkspaceMember[] */
    public function findByWorkspace(Workspace $workspace): array
    {
        return $this->createQueryBuilder('m')
            ->join('m.user', 'u')
            ->addSelect('u')
            ->andWhere('m.workspace = :ws')
            ->setParameter('ws', $workspace->getId(), 'uuid')
            ->orderBy('m.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * All explicit workspace memberships across a merchant's workspaces —
     * the company-level access overview.
     *
     * @return WorkspaceMember[]
     */
    public function findByMerchant(Merchant $merchant): array
    {
        return $this->createQueryBuilder('m')
            ->join('m.workspace', 'w')
            ->addSelect('w')
            ->join('m.user', 'u')
            ->addSelect('u')
            ->andWhere('w.merchant = :merchant')
            ->setParameter('merchant', $merchant->getId(), 'uuid')
            ->orderBy('w.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** The workspaces of one merchant that the user has an explicit membership in. */
    /** @return Workspace[] */
    public function findWorkspacesForUser(User $user, Merchant $merchant): array
    {
        $rows = $this->createQueryBuilder('m')
            ->join('m.workspace', 'w')
            ->addSelect('w')
            ->andWhere('m.user = :user')
            ->andWhere('w.merchant = :merchant')
            ->setParameter('user', $user->getId(), 'uuid')
            ->setParameter('merchant', $merchant->getId(), 'uuid')
            ->orderBy('w.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return array_map(static fn (WorkspaceMember $m) => $m->getWorkspace(), $rows);
    }
}

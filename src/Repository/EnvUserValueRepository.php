<?php

namespace App\Repository;

use App\Entity\Environment;
use App\Entity\EnvUserValue;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EnvUserValue>
 */
class EnvUserValueRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EnvUserValue::class);
    }

    /**
     * The user's overrides for one environment as name => value.
     *
     * @return array<string, string>
     */
    public function mapFor(Environment $environment, User $user): array
    {
        $rows = $this->createQueryBuilder('v')
            ->andWhere('v.environment = :env')
            ->andWhere('v.user = :user')
            ->setParameter('env', $environment->getId(), 'uuid')
            ->setParameter('user', $user->getId(), 'uuid')
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($rows as $row) {
            $map[$row->getName()] = $row->getValue() ?? '';
        }

        return $map;
    }

    /** @return EnvUserValue[] */
    public function findFor(Environment $environment, User $user): array
    {
        return $this->findBy(['environment' => $environment, 'user' => $user]);
    }
}

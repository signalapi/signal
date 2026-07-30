<?php

namespace App\Repository;

use App\Entity\Merchant;
use App\Entity\MerchantMember;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MerchantMember>
 */
class MerchantMemberRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MerchantMember::class);
    }

    public function findOneByUserAndMerchant(User $user, Merchant $merchant): ?MerchantMember
    {
        return $this->findOneBy(['user' => $user, 'merchant' => $merchant]);
    }

    /** @return MerchantMember[] */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('m')
            ->join('m.merchant', 'mer')
            ->andWhere('m.user = :user')
            ->andWhere('mer.active = true')
            ->setParameter('user', $user->getId(), 'uuid')
            ->orderBy('m.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return MerchantMember[] */
    public function findByMerchant(Merchant $merchant): array
    {
        return $this->createQueryBuilder('m')
            ->join('m.user', 'u')
            ->addSelect('u')
            ->andWhere('m.merchant = :merchant')
            ->setParameter('merchant', $merchant->getId(), 'uuid')
            ->orderBy('m.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}

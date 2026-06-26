<?php

namespace App\Repository;

use App\Entity\Merchant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Merchant>
 */
class MerchantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Merchant::class);
    }

    public function save(Merchant $merchant, bool $flush = true): void
    {
        $this->getEntityManager()->persist($merchant);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Merchant $merchant, bool $flush = true): void
    {
        $this->getEntityManager()->remove($merchant);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}

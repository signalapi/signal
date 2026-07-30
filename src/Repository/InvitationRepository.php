<?php

namespace App\Repository;

use App\Entity\Invitation;
use App\Entity\Merchant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Invitation>
 */
class InvitationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Invitation::class);
    }

    public function findOneByToken(string $plaintextToken): ?Invitation
    {
        return $this->findOneBy(['tokenHash' => hash('sha256', $plaintextToken)]);
    }

    /** @return Invitation[] */
    public function findPendingByMerchant(Merchant $merchant): array
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.merchant = :merchant')
            ->andWhere('i.acceptedAt IS NULL')
            ->andWhere('i.expiresAt > :now')
            ->setParameter('merchant', $merchant->getId(), 'uuid')
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('i.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}

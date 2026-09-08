<?php

namespace App\Repository;

use App\Entity\PlatformSetting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PlatformSetting>
 */
class PlatformSettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlatformSetting::class);
    }

    public function findOneByName(string $name): ?PlatformSetting
    {
        return $this->findOneBy(['name' => $name]);
    }

    public function save(PlatformSetting $setting, bool $flush = true): void
    {
        $this->getEntityManager()->persist($setting);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(PlatformSetting $setting, bool $flush = true): void
    {
        $this->getEntityManager()->remove($setting);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}

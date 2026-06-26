<?php

namespace App\Repository;

use App\Entity\ApiRequest;
use App\Entity\ResponseHistory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ResponseHistory>
 */
class ResponseHistoryRepository extends ServiceEntityRepository
{
    private const KEEP = 20;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ResponseHistory::class);
    }

    /**
     * Persists a history entry and prunes older ones beyond the per-request limit.
     */
    public function record(ResponseHistory $history): void
    {
        $em = $this->getEntityManager();
        $em->persist($history);
        $em->flush();

        $stale = $this->findBy(
            ['apiRequest' => $history->getApiRequest()],
            ['createdAt' => 'DESC'],
            1000,
            self::KEEP,
        );
        foreach ($stale as $old) {
            $em->remove($old);
        }
        if ($stale) {
            $em->flush();
        }
    }

    /**
     * @return ResponseHistory[]
     */
    public function findRecent(ApiRequest $request): array
    {
        return $this->findBy(['apiRequest' => $request], ['createdAt' => 'DESC'], self::KEEP);
    }
}

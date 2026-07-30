<?php

namespace App\Service;

use App\Entity\ApiRequest;

/**
 * The four buckets of a catalog update, keyed for the review screen.
 */
final class CollectionUpdatePlan
{
    /**
     * @param list<array{tag: string, request: ApiRequest}>              $added     upstream-new operations (transient requests)
     * @param list<array{existing: ApiRequest, incoming: ApiRequest}>    $autoApply upstream changed, untouched locally
     * @param list<array{existing: ApiRequest, incoming: ApiRequest}>    $conflicts changed on both sides
     * @param list<ApiRequest>                                           $removed   gone upstream, still live locally
     */
    public function __construct(
        public readonly array $added,
        public readonly array $autoApply,
        public readonly array $conflicts,
        public readonly array $removed,
        public readonly int $unchanged,
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->added && [] === $this->autoApply && [] === $this->conflicts && [] === $this->removed;
    }
}

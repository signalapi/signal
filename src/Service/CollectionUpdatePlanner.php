<?php

namespace App\Service;

use App\Entity\ApiCollection;
use App\Entity\ApiRequest;
use App\Entity\CatalogApiVersion;

/**
 * Three-way diff between a catalog-imported collection and a newer catalog
 * version, matched by origin_key:
 *
 *   upstream new                              -> added
 *   upstream changed + local untouched        -> autoApply
 *   upstream changed + local changed          -> conflict (user decides)
 *   upstream removed + still live locally     -> removed (deprecate, never delete)
 *   upstream unchanged                        -> untouched, local edits always win
 *
 * Hand-made requests (no origin_key) are invisible to the diff.
 */
class CollectionUpdatePlanner
{
    public function __construct(private readonly OpenApiImporter $importer)
    {
    }

    public function plan(ApiCollection $collection, CatalogApiVersion $target): CollectionUpdatePlan
    {
        /** @var array<string, array{tag: string, request: ApiRequest}> $incoming */
        $incoming = [];
        foreach ($this->importer->buildRequests($target->getSpec()) as $item) {
            $incoming[(string) $item['request']->getOriginKey()] = $item;
        }

        /** @var array<string, ApiRequest> $existingByKey */
        $existingByKey = [];
        foreach ($collection->getRequests() as $request) {
            if (null !== $request->getOriginKey()) {
                $existingByKey[$request->getOriginKey()] = $request;
            }
        }

        $added = [];
        $autoApply = [];
        $conflicts = [];
        $removed = [];
        $unchanged = 0;

        foreach ($incoming as $key => $item) {
            $existing = $existingByKey[$key] ?? null;
            if (null === $existing) {
                $added[] = $item;
                continue;
            }

            if ($item['request']->getOriginHash() === $existing->getOriginHash()) {
                ++$unchanged;
                continue;
            }

            // Upstream changed; did we? Missing origin_hash counts as "unknown" -> conflict.
            $locallyEdited = null === $existing->getOriginHash()
                || RequestProvenance::hash($existing) !== $existing->getOriginHash();

            $pair = ['existing' => $existing, 'incoming' => $item['request']];
            if ($locallyEdited) {
                $conflicts[] = $pair;
            } else {
                $autoApply[] = $pair;
            }
        }

        foreach ($existingByKey as $key => $existing) {
            if (!isset($incoming[$key]) && !$existing->isDeprecated()) {
                $removed[] = $existing;
            }
        }

        return new CollectionUpdatePlan($added, $autoApply, $conflicts, $removed, $unchanged);
    }
}

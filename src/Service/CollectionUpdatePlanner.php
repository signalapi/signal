<?php

namespace App\Service;

use App\Entity\ApiCollection;
use App\Entity\ApiRequest;
use App\Entity\CatalogApiVersion;

/**
 * Three-way diff between a forked collection and its source, matched by
 * origin_key:
 *
 *   upstream new                              -> added
 *   upstream changed + local untouched        -> autoApply
 *   upstream changed + local changed          -> conflict (user decides)
 *   upstream removed + still live locally     -> removed (deprecate, never delete)
 *   upstream unchanged                        -> untouched, local edits always win
 *
 * The source is either a published catalog version (immutable spec) or another
 * collection in the app — the same engine serves both, because the diff runs on
 * request entities rather than on spec documents.
 *
 * Hand-made requests (no origin_key) are invisible to the diff.
 */
class CollectionUpdatePlanner
{
    public function __construct(private readonly OpenApiImporter $importer)
    {
    }

    /** Diff against a newer published catalog version. */
    public function planFromCatalog(ApiCollection $collection, CatalogApiVersion $target): CollectionUpdatePlan
    {
        return $this->diff($collection, $this->importer->buildRequests($target->getSpec()));
    }

    /** Diff against the current state of the collection this one was forked from. */
    public function planFromCollection(ApiCollection $collection, ApiCollection $source): CollectionUpdatePlan
    {
        return $this->diff($collection, self::snapshot($source));
    }

    /**
     * The source collection's live requests as detached copies with provenance
     * filled in — the same shape OpenApiImporter::buildRequests() returns, so
     * the diff cannot tell the two sources apart.
     *
     * @return list<array{tag: string, request: ApiRequest}>
     */
    public static function snapshot(ApiCollection $source): array
    {
        $items = [];
        foreach ($source->getRequests() as $request) {
            if ($request->isDeprecated()) {
                continue; // a deprecated request is not something to hand downstream
            }
            $copy = self::detach($request);
            $items[] = ['tag' => self::topFolderName($request), 'request' => $copy];
        }

        return $items;
    }

    /**
     * A transient copy carrying the fields a fork owns, keyed so downstream
     * collections can match it: an upstream origin_key is passed through
     * (a chain shares one identity), otherwise one is derived.
     */
    public static function detach(ApiRequest $request): ApiRequest
    {
        $copy = new ApiRequest();
        $copy->setName($request->getName());
        $copy->setMethod($request->getMethod());
        $copy->setUrl($request->getUrl());
        $copy->setHeaders($request->getHeaders());
        $copy->setQueryParams($request->getQueryParams());
        $copy->setBodyMode($request->getBodyMode());
        $copy->setBody($request->getBody());
        $copy->setAuth($request->getAuth());

        $path = (string) (parse_url($request->getUrl(), \PHP_URL_PATH) ?: $request->getUrl());
        $copy->setOriginKey($request->getOriginKey() ?? RequestProvenance::key($request->getMethod(), $path));
        $copy->setOriginHash(RequestProvenance::hash($copy));

        return $copy;
    }

    /** The name of the request's outermost folder, used as its group label. */
    private static function topFolderName(ApiRequest $request): string
    {
        $folder = $request->getFolder();
        if (null === $folder) {
            return '';
        }
        while (null !== $folder->getParent()) {
            $folder = $folder->getParent();
        }

        return $folder->getName();
    }

    /**
     * @param list<array{tag: string, request: ApiRequest}> $incomingItems
     */
    private function diff(ApiCollection $collection, array $incomingItems): CollectionUpdatePlan
    {
        /** @var array<string, array{tag: string, request: ApiRequest}> $incoming */
        $incoming = [];
        foreach ($incomingItems as $item) {
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

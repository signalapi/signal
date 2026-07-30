<?php

namespace App\Service;

use App\Entity\ApiCollection;
use App\Entity\ApiRequest;
use App\Entity\Folder;
use App\Entity\ResponseExample;
use App\Entity\Workspace;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Copies a collection into a workspace, recording where it came from so the
 * fork can later pull the source's changes through CollectionUpdatePlanner.
 *
 * The copy is a snapshot: edits on either side stay put until someone pulls.
 * Forking a fork is allowed but its source is the collection it was copied
 * from, not the original — a pull only ever reaches one level up, which keeps
 * "who am I pulling from" answerable.
 */
class CollectionForker
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function fork(ApiCollection $source, Workspace $target, ?string $name = null): ApiCollection
    {
        $fork = new ApiCollection();
        $fork->setWorkspace($target);
        $fork->setName($name ?: $source->getName());
        $fork->setDescription($source->getDescription());
        $fork->setSourceType('collection');
        $fork->setSourceCollection($source);
        // A fork of a catalog import is downstream of the collection, not of the
        // catalog: only one source can offer pulls.
        $this->em->persist($fork);

        // Rebuild the folder tree first so requests can be re-parented.
        /** @var array<string, Folder> $folderMap source folder id => new folder */
        $folderMap = [];
        foreach ($this->foldersParentFirst($source) as $folder) {
            $copy = new Folder();
            $copy->setCollection($fork);
            $copy->setName($folder->getName());
            $copy->setPosition($folder->getPosition());
            $parent = $folder->getParent();
            if (null !== $parent) {
                $copy->setParent($folderMap[(string) $parent->getId()] ?? null);
            }
            $this->em->persist($copy);
            $folderMap[(string) $folder->getId()] = $copy;
        }

        foreach ($source->getRequests() as $request) {
            $copy = CollectionUpdatePlanner::detach($request);
            $copy->setCollection($fork);
            $copy->setPosition($request->getPosition());
            $copy->setDeprecated($request->isDeprecated());
            $folder = $request->getFolder();
            if (null !== $folder) {
                $copy->setFolder($folderMap[(string) $folder->getId()] ?? null);
            }
            $this->em->persist($copy);

            $this->copyExamples($request, $copy);
        }

        $this->em->flush();

        return $fork;
    }

    /**
     * Folders ordered so a parent always precedes its children.
     *
     * @return Folder[]
     */
    private function foldersParentFirst(ApiCollection $source): array
    {
        $folders = $source->getFolders()->toArray();
        usort($folders, static fn (Folder $a, Folder $b) => self::depth($a) <=> self::depth($b));

        return $folders;
    }

    private static function depth(Folder $folder): int
    {
        $depth = 0;
        while (null !== $folder->getParent()) {
            $folder = $folder->getParent();
            ++$depth;
        }

        return $depth;
    }

    private function copyExamples(ApiRequest $source, ApiRequest $target): void
    {
        foreach ($source->getExamples() as $example) {
            $copy = new ResponseExample();
            $copy->setApiRequest($target);
            $copy->setSource($example->getSource());
            $copy->setName($example->getName());
            $copy->setStatusCode($example->getStatusCode());
            $copy->setMethod($example->getMethod());
            $copy->setUrl($example->getUrl());
            $copy->setResponseHeaders($example->getResponseHeaders());
            $copy->setResponseBody($example->getResponseBody());
            $copy->setPosition($example->getPosition());
            $this->em->persist($copy);
        }
    }
}

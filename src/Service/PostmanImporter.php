<?php

namespace App\Service;

use App\Entity\ApiCollection;
use App\Entity\ApiRequest;
use App\Entity\Environment;
use App\Entity\EnvVariable;
use App\Entity\Folder;
use App\Entity\ResponseExample;
use App\Entity\Workspace;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Imports Postman Collection v2.1 and Environment exports into our own model.
 */
class PostmanImporter
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * @param array<string, mixed> $data Decoded Postman collection JSON
     */
    public function importCollection(array $data, Workspace $workspace): ApiCollection
    {
        if (!isset($data['item']) || !\is_array($data['item'])) {
            throw new \InvalidArgumentException('Geçerli bir Postman collection değil ("item" alanı yok).');
        }

        $collection = new ApiCollection();
        $collection->setWorkspace($workspace);
        $collection->setName((string) ($data['info']['name'] ?? 'İçe aktarılan collection'));
        $collection->setDescription($this->stringOrNull($data['info']['description'] ?? null));
        $this->em->persist($collection);

        $position = 0;
        foreach ($data['item'] as $item) {
            $this->importItem($item, $collection, null, $position);
        }

        $this->em->flush();

        return $collection;
    }

    /**
     * @param array<string, mixed> $data Decoded Postman environment JSON
     */
    public function importEnvironment(array $data, Workspace $workspace): Environment
    {
        $env = new Environment();
        $env->setWorkspace($workspace);
        $env->setName((string) ($data['name'] ?? 'İçe aktarılan environment'));

        foreach (($data['values'] ?? []) as $value) {
            if (!\is_array($value) || !isset($value['key'])) {
                continue;
            }
            $variable = new EnvVariable();
            $variable->setName((string) $value['key']);
            $variable->setValue($this->stringOrNull($value['value'] ?? null));
            $variable->setSecret('secret' === ($value['type'] ?? null));
            $env->addVariable($variable);
        }

        $this->em->persist($env);
        $this->em->flush();

        return $env;
    }

    /**
     * Creates an Environment from a collection's top-level "variable" array
     * (collection-level variables), if any. Returns null when there are none.
     *
     * @param array<string, mixed> $data Decoded Postman collection JSON
     */
    public function importCollectionVariables(array $data, Workspace $workspace, string $name): ?Environment
    {
        $variables = $data['variable'] ?? null;
        if (!\is_array($variables) || [] === $variables) {
            return null;
        }

        $env = new Environment();
        $env->setWorkspace($workspace);
        $env->setName($name);

        foreach ($variables as $v) {
            if (!\is_array($v) || !isset($v['key'])) {
                continue;
            }
            $variable = new EnvVariable();
            $variable->setName((string) $v['key']);
            $variable->setValue($this->stringOrNull($v['value'] ?? null));
            $variable->setSecret('secret' === ($v['type'] ?? null));
            $env->addVariable($variable);
        }

        if ($env->getVariables()->isEmpty()) {
            return null;
        }

        $this->em->persist($env);
        $this->em->flush();

        return $env;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function importItem(array $item, ApiCollection $collection, ?Folder $parent, int &$position): void
    {
        // Folder: has a nested "item" array.
        if (isset($item['item']) && \is_array($item['item'])) {
            $folder = new Folder();
            $folder->setCollection($collection);
            $folder->setParent($parent);
            $folder->setName((string) ($item['name'] ?? 'Folder'));
            $folder->setPosition($position++);
            $this->em->persist($folder);

            $childPos = 0;
            foreach ($item['item'] as $child) {
                $this->importItem($child, $collection, $folder, $childPos);
            }

            return;
        }

        // Request leaf.
        if (isset($item['request'])) {
            $this->em->persist($this->buildRequest($item, $collection, $parent, $position++));
        }
    }

    /**
     * @param array<string, mixed> $item
     */
    private function buildRequest(array $item, ApiCollection $collection, ?Folder $folder, int $position): ApiRequest
    {
        $req = $item['request'];
        $req = \is_string($req) ? ['url' => $req, 'method' => 'GET'] : $req;

        $request = new ApiRequest();
        $request->setCollection($collection);
        $request->setFolder($folder);
        $request->setName((string) ($item['name'] ?? 'İsimsiz istek'));
        $request->setMethod((string) ($req['method'] ?? 'GET'));
        $request->setPosition($position);

        // URL
        $url = $req['url'] ?? '';
        if (\is_array($url)) {
            $request->setUrl((string) ($url['raw'] ?? ''));
            $query = [];
            foreach (($url['query'] ?? []) as $q) {
                if (\is_array($q) && isset($q['key']) && empty($q['disabled'])) {
                    $query[] = ['name' => (string) $q['key'], 'value' => (string) ($q['value'] ?? '')];
                }
            }
            $request->setQueryParams($query);
        } else {
            $request->setUrl((string) $url);
        }

        // Headers
        $headers = [];
        foreach (($req['header'] ?? []) as $h) {
            if (\is_array($h) && isset($h['key']) && empty($h['disabled'])) {
                $headers[] = ['name' => (string) $h['key'], 'value' => (string) ($h['value'] ?? '')];
            }
        }
        $request->setHeaders($headers);

        // Body
        $this->applyBody($request, $req['body'] ?? null);

        // Saved example responses (Postman stores these in item.response[]).
        $this->importExamples($item['response'] ?? null, $request);

        return $request;
    }

    /**
     * Imports Postman's per-request saved responses (item.response[]) as named examples.
     */
    private function importExamples(mixed $responses, ApiRequest $request): void
    {
        if (!\is_array($responses)) {
            return;
        }

        $pos = 0;
        foreach ($responses as $r) {
            if (!\is_array($r)) {
                continue;
            }
            $code = isset($r['code']) && is_numeric($r['code']) ? (int) $r['code'] : null;

            $headers = [];
            foreach (($r['header'] ?? []) as $h) {
                if (\is_array($h) && isset($h['key'])) {
                    $headers[(string) $h['key']] = (string) ($h['value'] ?? '');
                }
            }

            // The request that produced this example (for method/url context).
            $orig = $r['originalRequest'] ?? [];
            $method = \is_array($orig) ? (string) ($orig['method'] ?? $request->getMethod()) : $request->getMethod();
            $url = $request->getUrl();
            if (\is_array($orig) && isset($orig['url'])) {
                $u = $orig['url'];
                $url = \is_array($u) ? (string) ($u['raw'] ?? $url) : (string) $u;
            }

            $name = trim((string) ($r['name'] ?? '')) ?: trim((string) ($r['status'] ?? ''));
            if ('' === $name) {
                $name = null === $code ? 'Örnek' : ($code . ' Response');
            }

            $ex = new ResponseExample();
            $ex->setApiRequest($request);
            $ex->setSource(ResponseExample::SOURCE_IMPORTED);
            $ex->setName($name);
            $ex->setStatusCode($code);
            $ex->setMethod($method);
            $ex->setUrl($url);
            $ex->setResponseHeaders($headers);
            $ex->setResponseBody(isset($r['body']) ? mb_substr((string) $r['body'], 0, 50000) : null);
            $ex->setPosition($pos++);
            $this->em->persist($ex);
        }
    }

    private function applyBody(ApiRequest $request, mixed $body): void
    {
        if (!\is_array($body)) {
            $request->setBodyMode('none');

            return;
        }

        $mode = $body['mode'] ?? 'none';

        switch ($mode) {
            case 'raw':
                $language = $body['options']['raw']['language'] ?? null;
                $request->setBodyMode('json' === $language ? 'json' : 'raw');
                $request->setBody($this->stringOrNull($body['raw'] ?? null));
                break;

            case 'urlencoded':
            case 'formdata':
                $pairs = [];
                foreach (($body[$mode] ?? []) as $p) {
                    if (\is_array($p) && isset($p['key']) && empty($p['disabled'])) {
                        $pairs[(string) $p['key']] = (string) ($p['value'] ?? '');
                    }
                }
                $request->setBodyMode('form');
                $request->setBody(http_build_query($pairs));
                break;

            default:
                $request->setBodyMode('none');
        }
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (null === $value || '' === $value) {
            return null;
        }

        return \is_string($value) ? $value : (string) json_encode($value);
    }
}

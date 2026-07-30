<?php

namespace App\Controller\App;

use App\Entity\ApiCollection;
use App\Entity\ApiRequest;
use App\Entity\Environment;
use App\Entity\Folder;
use App\Entity\ResponseHistory;
use App\Entity\Workspace;
use App\Repository\ApiRequestRepository;
use App\Repository\EnvironmentRepository;
use App\Repository\FolderRepository;
use App\Repository\ResponseHistoryRepository;
use App\Service\RequestRunner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * JSON endpoints powering the Postman-style collection workbench (no page reloads).
 * Session-authenticated under /app; CSRF validated via the X-CSRF-Token header.
 */
#[Route('/app/workspaces/{workspace}/wb')]
#[IsGranted('ROLE_USER')]
class WorkbenchController extends AbstractAppController
{
    #[Route('/requests/{request}', name: 'wb_request_get', methods: ['GET'])]
    public function getRequest(
        Workspace $workspace,
        #[MapEntity(mapping: ['request' => 'id'])] ApiRequest $apiRequest,
    ): JsonResponse {
        $this->assertWorkspace($workspace);
        $this->assertRequest($workspace, $apiRequest);

        return new JsonResponse($this->serialize($apiRequest));
    }

    #[Route('/requests/{request}/save', name: 'wb_request_save', methods: ['POST'])]
    public function saveRequest(
        Workspace $workspace,
        #[MapEntity(mapping: ['request' => 'id'])] ApiRequest $apiRequest,
        Request $httpRequest,
        ApiRequestRepository $requests,
    ): JsonResponse {
        $this->assertWorkspace($workspace, 'edit');
        $this->assertRequest($workspace, $apiRequest);
        $this->assertCsrf($httpRequest);

        $this->apply($apiRequest, $this->jsonBody($httpRequest));
        $requests->save($apiRequest);

        return new JsonResponse(['ok' => true, 'request' => $this->serialize($apiRequest)]);
    }

    #[Route('/requests/{request}/send', name: 'wb_request_send', methods: ['POST'])]
    public function sendRequest(
        Workspace $workspace,
        #[MapEntity(mapping: ['request' => 'id'])] ApiRequest $apiRequest,
        Request $httpRequest,
        EnvironmentRepository $environments,
        RequestRunner $runner,
        \App\Repository\ResponseHistoryRepository $historyRepo,
        \App\Repository\ResponseExampleRepository $exampleRepo,
    ): JsonResponse {
        $this->assertWorkspace($workspace);
        $this->assertRequest($workspace, $apiRequest);
        $this->assertCsrf($httpRequest);

        $body = $this->jsonBody($httpRequest);
        // Run against a clone with the on-screen values so we do NOT persist edits
        // (a later flush for history would otherwise save the live entity's changes).
        $sendCopy = clone $apiRequest;
        $this->apply($sendCopy, $body);

        $vars = [];
        $envName = null;
        $envId = (string) ($body['environment'] ?? '');
        if ('' !== $envId) {
            $env = $environments->find($envId);
            if ($env instanceof Environment && $env->getWorkspace()->getId()?->toRfc4122() === $workspace->getId()?->toRfc4122()) {
                $vars = $env->toMap();
                $envName = $env->getName();
            }
        }

        $result = $runner->send($sendCopy, $vars, $workspace, $this->currentUser());

        $headers = [];
        foreach ($result->headers as $k => $values) {
            $headers[$k] = implode(', ', $values);
        }

        // Save to per-request response history (keeps the last N).
        $size = null === $result->body ? 0 : \strlen($result->body);
        $entry = new \App\Entity\ResponseHistory();
        $entry->setApiRequest($apiRequest);
        $entry->setUser($this->currentUser());
        $entry->setMethod($result->method);
        $entry->setUrl($result->url);
        $entry->setStatusCode($result->statusCode);
        $entry->setDurationMs((int) round($result->durationMs));
        $entry->setSize($size);
        $entry->setEnvironmentName($envName);
        $entry->setResponseHeaders($headers);
        $entry->setResponseBody(null === $result->body ? null : mb_substr($result->body, 0, 50000));
        $entry->setError($result->error);
        $historyRepo->record($entry);

        // Auto-capture: if this request has no saved example for this status code yet,
        // keep the response as a named example (so a library of Success/Fail examples
        // builds up as you test, even for collections that shipped without examples).
        $autoExample = null;
        if (null !== $result->statusCode && null === $exampleRepo->findOneForRequestByStatus($apiRequest, $result->statusCode)) {
            $ex = new \App\Entity\ResponseExample();
            $ex->setApiRequest($apiRequest);
            $ex->setSource(\App\Entity\ResponseExample::SOURCE_AUTO);
            $ex->setName($this->statusLabel($result->statusCode));
            $ex->setStatusCode($result->statusCode);
            $ex->setMethod($result->method);
            $ex->setUrl($result->url);
            $ex->setResponseHeaders($headers);
            $ex->setResponseBody(null === $result->body ? null : mb_substr($result->body, 0, 50000));
            $ex->setPosition($exampleRepo->nextPosition($apiRequest));
            $exampleRepo->save($ex);
            $autoExample = $ex;
        }

        return new JsonResponse([
            'ok' => $result->ok,
            'status' => $result->statusCode,
            'durationMs' => round($result->durationMs),
            'size' => null === $result->body ? 0 : \strlen($result->body),
            'headers' => $headers,
            'body' => $result->body,
            'error' => $result->error,
            'newExample' => null === $autoExample ? null : $this->exampleSummary($autoExample),
            'examples' => $this->exampleList($apiRequest, $exampleRepo),
        ]);
    }

    #[Route('/requests/{request}/examples', name: 'wb_example_create', methods: ['POST'])]
    public function createExample(
        Workspace $workspace,
        #[MapEntity(mapping: ['request' => 'id'])] ApiRequest $apiRequest,
        Request $httpRequest,
        \App\Repository\ResponseExampleRepository $exampleRepo,
    ): JsonResponse {
        $this->assertWorkspace($workspace, 'edit');
        $this->assertRequest($workspace, $apiRequest);
        $this->assertCsrf($httpRequest);

        $body = $this->jsonBody($httpRequest);
        $status = isset($body['status']) && is_numeric($body['status']) ? (int) $body['status'] : null;
        $headers = [];
        foreach ((array) ($body['headers'] ?? []) as $k => $v) {
            $headers[(string) $k] = \is_scalar($v) ? (string) $v : (string) json_encode($v);
        }

        $ex = new \App\Entity\ResponseExample();
        $ex->setApiRequest($apiRequest);
        $ex->setSource(\App\Entity\ResponseExample::SOURCE_MANUAL);
        $ex->setName(trim((string) ($body['name'] ?? '')) ?: $this->statusLabel($status));
        $ex->setStatusCode($status);
        $ex->setMethod((string) ($body['method'] ?? $apiRequest->getMethod()));
        $ex->setUrl((string) ($body['url'] ?? $apiRequest->getUrl()));
        $ex->setResponseHeaders($headers);
        $ex->setResponseBody(isset($body['body']) ? mb_substr((string) $body['body'], 0, 50000) : null);
        $ex->setPosition($exampleRepo->nextPosition($apiRequest));
        $exampleRepo->save($ex);

        return new JsonResponse(['ok' => true, 'example' => $this->exampleSummary($ex), 'examples' => $this->exampleList($apiRequest, $exampleRepo)]);
    }

    #[Route('/examples/{example}', name: 'wb_example_get', methods: ['GET'])]
    public function getExample(
        Workspace $workspace,
        #[MapEntity(mapping: ['example' => 'id'])] \App\Entity\ResponseExample $example,
    ): JsonResponse {
        $this->assertWorkspace($workspace);
        if ($example->getApiRequest()->getCollection()->getWorkspace()->getId()?->toRfc4122() !== $workspace->getId()?->toRfc4122()) {
            throw $this->createNotFoundException();
        }

        return new JsonResponse([
            'id' => (string) $example->getId(),
            'name' => $example->getName(),
            'source' => $example->getSource(),
            'method' => $example->getMethod(),
            'url' => $example->getUrl(),
            'status' => $example->getStatusCode(),
            'headers' => $example->getResponseHeaders(),
            'body' => $example->getResponseBody(),
        ]);
    }

    #[Route('/examples/{example}/delete', name: 'wb_example_delete', methods: ['POST'])]
    public function deleteExample(
        Workspace $workspace,
        #[MapEntity(mapping: ['example' => 'id'])] \App\Entity\ResponseExample $example,
        Request $httpRequest,
        \App\Repository\ResponseExampleRepository $exampleRepo,
    ): JsonResponse {
        $this->assertWorkspace($workspace, 'edit');
        if ($example->getApiRequest()->getCollection()->getWorkspace()->getId()?->toRfc4122() !== $workspace->getId()?->toRfc4122()) {
            throw $this->createNotFoundException();
        }
        $this->assertCsrf($httpRequest);

        $request = $example->getApiRequest();
        $exampleRepo->remove($example);

        return new JsonResponse(['ok' => true, 'examples' => $this->exampleList($request, $exampleRepo)]);
    }

    #[Route('/collections/{collection}/requests', name: 'wb_request_create', methods: ['POST'])]
    public function createRequest(
        Workspace $workspace,
        #[MapEntity(mapping: ['collection' => 'id'])] ApiCollection $collection,
        Request $httpRequest,
        ApiRequestRepository $requests,
    ): JsonResponse {
        $this->assertWorkspace($workspace, 'edit');
        if ($collection->getWorkspace()->getId()?->toRfc4122() !== $workspace->getId()?->toRfc4122()) {
            throw $this->createNotFoundException();
        }
        $this->assertCsrf($httpRequest);

        $body = $this->jsonBody($httpRequest);
        $req = new ApiRequest();
        $req->setCollection($collection);
        $req->setName(trim((string) ($body['name'] ?? '')) ?: $this->translator->trans('New request'));
        $req->setMethod('GET');
        $req->setUrl('');
        $requests->save($req);

        return new JsonResponse(['ok' => true, 'request' => $this->serialize($req)]);
    }

    #[Route('/collections/{collection}/folders', name: 'wb_folder_create', methods: ['POST'])]
    public function createFolder(
        Workspace $workspace,
        #[MapEntity(mapping: ['collection' => 'id'])] ApiCollection $collection,
        Request $httpRequest,
        FolderRepository $folders,
    ): JsonResponse {
        $this->assertWorkspace($workspace, 'edit');
        if ($collection->getWorkspace()->getId()?->toRfc4122() !== $workspace->getId()?->toRfc4122()) {
            throw $this->createNotFoundException();
        }
        $this->assertCsrf($httpRequest);

        $body = $this->jsonBody($httpRequest);

        $parent = null;
        $parentId = (string) ($body['parentId'] ?? '');
        if ('' !== $parentId) {
            $candidate = $folders->find($parentId);
            if ($candidate instanceof Folder && $candidate->getCollection()->getId()?->toRfc4122() === $collection->getId()?->toRfc4122()) {
                $parent = $candidate;
            }
        }

        $maxPos = -1;
        foreach ($collection->getFolders() as $f) {
            if ($f->getParent()?->getId()?->toRfc4122() === $parent?->getId()?->toRfc4122()) {
                $maxPos = max($maxPos, $f->getPosition());
            }
        }

        $folder = new Folder();
        $folder->setCollection($collection);
        $folder->setParent($parent);
        $folder->setName(trim((string) ($body['name'] ?? '')) ?: $this->translator->trans('New folder'));
        $folder->setPosition($maxPos + 1);
        $folders->save($folder);

        return new JsonResponse(['ok' => true, 'folder' => [
            'id' => (string) $folder->getId(),
            'name' => $folder->getName(),
            'parentId' => $parent?->getId() ? (string) $parent->getId() : null,
        ]]);
    }

    #[Route('/collections/{collection}/requests/reorder', name: 'wb_request_reorder', methods: ['POST'])]
    public function reorderRequests(
        Workspace $workspace,
        #[MapEntity(mapping: ['collection' => 'id'])] ApiCollection $collection,
        Request $httpRequest,
        ApiRequestRepository $requests,
        FolderRepository $folders,
        EntityManagerInterface $em,
    ): JsonResponse {
        $this->assertWorkspace($workspace, 'edit');
        if ($collection->getWorkspace()->getId()?->toRfc4122() !== $workspace->getId()?->toRfc4122()) {
            throw $this->createNotFoundException();
        }
        $this->assertCsrf($httpRequest);

        $body = $this->jsonBody($httpRequest);

        $folder = null;
        $folderId = (string) ($body['folderId'] ?? '');
        if ('' !== $folderId) {
            $candidate = $folders->find($folderId);
            if ($candidate instanceof Folder && $candidate->getCollection()->getId()?->toRfc4122() === $collection->getId()?->toRfc4122()) {
                $folder = $candidate;
            }
        }

        $pos = 0;
        foreach ((array) ($body['order'] ?? []) as $rid) {
            $r = $requests->find((string) $rid);
            if ($r instanceof ApiRequest && $r->getCollection()->getId()?->toRfc4122() === $collection->getId()?->toRfc4122()) {
                $r->setFolder($folder);
                $r->setPosition($pos++);
            }
        }
        $em->flush();

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/requests/{request}/delete', name: 'wb_request_delete', methods: ['POST'])]
    public function deleteRequest(
        Workspace $workspace,
        #[MapEntity(mapping: ['request' => 'id'])] ApiRequest $apiRequest,
        Request $httpRequest,
        ApiRequestRepository $requests,
    ): JsonResponse {
        $this->assertWorkspace($workspace, 'edit');
        $this->assertRequest($workspace, $apiRequest);
        $this->assertCsrf($httpRequest);

        $requests->remove($apiRequest);

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/requests/{request}/history', name: 'wb_history_list', methods: ['GET'])]
    public function historyList(
        Workspace $workspace,
        #[MapEntity(mapping: ['request' => 'id'])] ApiRequest $apiRequest,
        ResponseHistoryRepository $historyRepo,
    ): JsonResponse {
        $this->assertWorkspace($workspace);
        $this->assertRequest($workspace, $apiRequest);

        $items = array_map(static fn (ResponseHistory $h): array => [
            'id' => (string) $h->getId(),
            'method' => $h->getMethod(),
            'status' => $h->getStatusCode(),
            'durationMs' => $h->getDurationMs(),
            'size' => $h->getSize(),
            'env' => $h->getEnvironmentName(),
            'user' => $h->getUser()?->getName(),
            'error' => null !== $h->getError(),
            'createdAt' => $h->getCreatedAt()->format(\DATE_ATOM),
        ], $historyRepo->findRecent($apiRequest));

        return new JsonResponse(['items' => $items]);
    }

    #[Route('/history/{history}', name: 'wb_history_detail', methods: ['GET'])]
    public function historyDetail(
        Workspace $workspace,
        #[MapEntity(mapping: ['history' => 'id'])] ResponseHistory $history,
    ): JsonResponse {
        $this->assertWorkspace($workspace);
        if ($history->getApiRequest()->getCollection()->getWorkspace()->getId()?->toRfc4122() !== $workspace->getId()?->toRfc4122()) {
            throw $this->createNotFoundException();
        }

        return new JsonResponse([
            'method' => $history->getMethod(),
            'url' => $history->getUrl(),
            'status' => $history->getStatusCode(),
            'durationMs' => $history->getDurationMs(),
            'size' => $history->getSize(),
            'env' => $history->getEnvironmentName(),
            'headers' => $history->getResponseHeaders(),
            'body' => $history->getResponseBody(),
            'error' => $history->getError(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(ApiRequest $r): array
    {
        return [
            'id' => (string) $r->getId(),
            'name' => $r->getName(),
            'method' => $r->getMethod(),
            'url' => $r->getUrl(),
            'headers' => $r->getHeaders(),
            'params' => $r->getQueryParams(),
            'bodyMode' => $r->getBodyMode(),
            'body' => $r->getBody(),
            'auth' => $r->getAuth(),
            'examples' => array_map($this->exampleSummary(...), $r->getExamples()->toArray()),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function exampleList(ApiRequest $r, \App\Repository\ResponseExampleRepository $exampleRepo): array
    {
        return array_map($this->exampleSummary(...), $exampleRepo->findForRequest($r));
    }

    /**
     * @return array<string, mixed>
     */
    private function exampleSummary(\App\Entity\ResponseExample $e): array
    {
        return [
            'id' => (string) $e->getId(),
            'name' => $e->getName(),
            'status' => $e->getStatusCode(),
            'source' => $e->getSource(),
            'success' => $e->isSuccess(),
        ];
    }

    private function statusLabel(?int $code): string
    {
        if (null === $code) {
            return $this->translator->trans('Example');
        }
        $text = \Symfony\Component\HttpFoundation\Response::$statusTexts[$code] ?? 'Response';

        return $code . ' ' . $text;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function apply(ApiRequest $r, array $data): void
    {
        if (isset($data['name'])) {
            $r->setName(trim((string) $data['name']) ?: $r->getName());
        }
        $r->setMethod((string) ($data['method'] ?? $r->getMethod()));
        $r->setUrl((string) ($data['url'] ?? ''));
        $r->setHeaders($this->pairs($data['headers'] ?? []));
        $r->setQueryParams($this->pairs($data['params'] ?? []));
        $mode = (string) ($data['bodyMode'] ?? 'none');
        $r->setBodyMode(\in_array($mode, ['none', 'raw', 'json', 'form'], true) ? $mode : 'none');
        $body = (string) ($data['body'] ?? '');
        $r->setBody('' === $body ? null : $body);
        $r->setAuth($this->sanitizeAuth($data['auth'] ?? []));
    }

    /**
     * @param mixed $auth
     *
     * @return array<string, string>
     */
    private function sanitizeAuth(mixed $auth): array
    {
        if (!\is_array($auth)) {
            return [];
        }
        $allowed = ['type', 'token', 'username', 'password', 'key', 'value', 'addTo'];
        $out = [];
        foreach ($allowed as $field) {
            if (isset($auth[$field]) && \is_scalar($auth[$field])) {
                $out[$field] = (string) $auth[$field];
            }
        }

        return $out;
    }

    /**
     * @param mixed $input
     *
     * @return array<int, array{name: string, value: string}>
     */
    private function pairs(mixed $input): array
    {
        $out = [];
        if (\is_array($input)) {
            foreach ($input as $row) {
                if (!\is_array($row)) {
                    continue;
                }
                $name = trim((string) ($row['name'] ?? ''));
                if ('' === $name) {
                    continue;
                }
                $out[] = ['name' => $name, 'value' => (string) ($row['value'] ?? '')];
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(Request $request): array
    {
        $data = json_decode((string) $request->getContent(), true);

        return \is_array($data) ? $data : [];
    }

    private function assertCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid('workbench', (string) $request->headers->get('X-CSRF-Token'))) {
            throw $this->createAccessDeniedException('CSRF');
        }
    }

    private function assertRequest(Workspace $workspace, ApiRequest $apiRequest): void
    {
        if ($apiRequest->getCollection()->getWorkspace()->getId()?->toRfc4122() !== $workspace->getId()?->toRfc4122()) {
            throw $this->createNotFoundException();
        }
    }
}

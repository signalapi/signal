<?php

namespace App\Controller\App;

use App\Entity\ApiCollection;
use App\Entity\ApiRequest;
use App\Entity\Environment;
use App\Entity\Workspace;
use App\Repository\ApiRequestRepository;
use App\Repository\EnvironmentRepository;
use App\Service\RequestResult;
use App\Service\RequestRunner;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/app/workspaces/{workspace}')]
#[IsGranted('ROLE_USER')]
class ApiRequestController extends AbstractAppController
{
    #[Route('/collections/{collection}/requests/new', name: 'app_request_new', methods: ['POST'])]
    public function new(
        Workspace $workspace,
        #[MapEntity(mapping: ['collection' => 'id'])] ApiCollection $collection,
        Request $request,
        ApiRequestRepository $requests,
        TranslatorInterface $translator,
    ): Response {
        $this->assertWorkspace($workspace, 'edit');

        if (!$this->isCsrfTokenValid('new-request', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $apiRequest = new ApiRequest();
        $apiRequest->setCollection($collection);
        $apiRequest->setName(trim((string) $request->request->get('name')) ?: $translator->trans('New request'));
        $apiRequest->setMethod('GET');
        $apiRequest->setUrl('https://');
        $requests->save($apiRequest);

        return $this->redirectToRoute('app_request_show', [
            'workspace' => $workspace->getId(),
            'request' => $apiRequest->getId(),
        ]);
    }

    #[Route('/requests/{request}', name: 'app_request_show', methods: ['GET'])]
    public function show(
        Workspace $workspace,
        #[MapEntity(mapping: ['request' => 'id'])] ApiRequest $apiRequest,
        EnvironmentRepository $environments,
    ): Response {
        $this->assertWorkspace($workspace);
        $this->assertRequest($workspace, $apiRequest);

        return $this->render('app/request/show.html.twig', [
            'workspace' => $workspace,
            'request' => $apiRequest,
            'environments' => $environments->findByWorkspace($workspace),
            'result' => null,
        ]);
    }

    #[Route('/requests/{request}/save', name: 'app_request_save', methods: ['POST'])]
    public function save(
        Workspace $workspace,
        #[MapEntity(mapping: ['request' => 'id'])] ApiRequest $apiRequest,
        Request $httpRequest,
        ApiRequestRepository $requests,
        TranslatorInterface $translator,
    ): Response {
        $this->assertWorkspace($workspace, 'edit');
        $this->assertRequest($workspace, $apiRequest);

        if (!$this->isCsrfTokenValid('save-request' . $apiRequest->getId(), (string) $httpRequest->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $this->applyForm($apiRequest, $httpRequest);
        $requests->save($apiRequest);
        $this->addFlash('success', $translator->trans('Request saved.'));

        return $this->redirectToRoute('app_request_show', [
            'workspace' => $workspace->getId(),
            'request' => $apiRequest->getId(),
        ]);
    }

    #[Route('/requests/{request}/send', name: 'app_request_send', methods: ['POST'])]
    public function send(
        Workspace $workspace,
        #[MapEntity(mapping: ['request' => 'id'])] ApiRequest $apiRequest,
        Request $httpRequest,
        ApiRequestRepository $requests,
        EnvironmentRepository $environments,
        RequestRunner $runner,
    ): Response {
        $this->assertWorkspace($workspace);
        $this->assertRequest($workspace, $apiRequest);

        if (!$this->isCsrfTokenValid('save-request' . $apiRequest->getId(), (string) $httpRequest->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        // Persist the on-screen edits, then run with them.
        $this->applyForm($apiRequest, $httpRequest);
        $requests->save($apiRequest);

        $selectedEnv = null;
        $vars = [];
        $envId = (string) $httpRequest->request->get('environment');
        if ('' !== $envId) {
            $selectedEnv = $environments->find($envId);
            if ($selectedEnv instanceof Environment && $selectedEnv->getWorkspace()->getId()?->toRfc4122() === $workspace->getId()?->toRfc4122()) {
                $vars = $selectedEnv->toMap();
            }
        }

        $result = $runner->send($apiRequest, $vars);

        return $this->render('app/request/show.html.twig', [
            'workspace' => $workspace,
            'request' => $apiRequest,
            'environments' => $environments->findByWorkspace($workspace),
            'result' => $result,
            'selected_env' => $selectedEnv?->getId(),
        ]);
    }

    #[Route('/requests/{request}/delete', name: 'app_request_delete', methods: ['POST'])]
    public function delete(
        Workspace $workspace,
        #[MapEntity(mapping: ['request' => 'id'])] ApiRequest $apiRequest,
        Request $httpRequest,
        ApiRequestRepository $requests,
        TranslatorInterface $translator,
    ): Response {
        $this->assertWorkspace($workspace, 'edit');
        $this->assertRequest($workspace, $apiRequest);

        if (!$this->isCsrfTokenValid('delete' . $apiRequest->getId(), (string) $httpRequest->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $collectionId = $apiRequest->getCollection()->getId();
        $requests->remove($apiRequest);
        $this->addFlash('success', $translator->trans('Request deleted.'));

        return $this->redirectToRoute('app_collection_show', [
            'workspace' => $workspace->getId(),
            'collection' => $collectionId,
        ]);
    }

    private function applyForm(ApiRequest $apiRequest, Request $request): void
    {
        $apiRequest->setName(trim((string) $request->request->get('name')) ?: $apiRequest->getName());
        $apiRequest->setMethod((string) $request->request->get('method', 'GET'));
        $apiRequest->setUrl(trim((string) $request->request->get('url')));
        $apiRequest->setHeaders($this->parsePairs((string) $request->request->get('headers'), ':'));
        $apiRequest->setQueryParams($this->parsePairs((string) $request->request->get('params'), '='));

        $bodyMode = (string) $request->request->get('bodyMode', 'none');
        $apiRequest->setBodyMode(\in_array($bodyMode, ['none', 'raw', 'json', 'form'], true) ? $bodyMode : 'none');
        $body = (string) $request->request->get('body');
        $apiRequest->setBody('' === $body ? null : $body);
    }

    /**
     * Parses a textarea of "key<sep>value" lines into ordered pairs.
     *
     * @return array<int, array{name: string, value: string}>
     */
    private function parsePairs(string $raw, string $separator): array
    {
        $pairs = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $line = trim($line);
            if ('' === $line || !str_contains($line, $separator)) {
                continue;
            }
            [$name, $value] = explode($separator, $line, 2);
            $name = trim($name);
            if ('' === $name) {
                continue;
            }
            $pairs[] = ['name' => $name, 'value' => trim($value)];
        }

        return $pairs;
    }

    private function assertRequest(Workspace $workspace, ApiRequest $apiRequest): void
    {
        if ($apiRequest->getCollection()->getWorkspace()->getId()?->toRfc4122() !== $workspace->getId()?->toRfc4122()) {
            throw $this->createNotFoundException();
        }
    }
}

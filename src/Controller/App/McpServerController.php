<?php

namespace App\Controller\App;

use App\Entity\McpServer;
use App\Entity\Workspace;
use App\Repository\EnvironmentRepository;
use App\Repository\McpServerRepository;
use App\Service\Mcp\McpCatalog;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * MCP servers: the endpoints a workspace tests against and the tools they
 * publish. The catalogue is what makes a step a choice rather than a thing
 * typed from memory.
 */
#[Route('/app/workspaces/{workspace}/mcp-servers')]
#[IsGranted('ROLE_USER')]
class McpServerController extends AbstractAppController
{
    public function __construct(private readonly McpServerRepository $servers)
    {
    }

    #[Route('', name: 'app_mcpserver_index', methods: ['GET'])]
    public function index(Workspace $workspace, EnvironmentRepository $environments): Response
    {
        $this->assertWorkspace($workspace);

        return $this->render('app/mcp_server/index.html.twig', [
            'workspace' => $workspace,
            'servers' => $this->servers->findByWorkspace($workspace),
            'environments' => $environments->findByWorkspace($workspace),
        ]);
    }

    #[Route('/new', name: 'app_mcpserver_create', methods: ['POST'])]
    public function create(
        Workspace $workspace,
        Request $request,
        EnvironmentRepository $environments,
        McpCatalog $catalog,
        TranslatorInterface $translator,
    ): Response {
        $this->assertWorkspace($workspace, 'edit');
        if (!$this->isCsrfTokenValid('new-mcp-server' . $workspace->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $name = trim((string) $request->request->get('name'));
        $url = trim((string) $request->request->get('url'));
        if ('' === $name || '' === $url) {
            $this->addFlash('error', $translator->trans('A name and an endpoint URL are required.'));

            return $this->redirectToRoute('app_mcpserver_index', ['workspace' => $workspace->getId()]);
        }
        if (null !== $this->servers->findOneByName($workspace, $name)) {
            $this->addFlash('error', $translator->trans('An MCP server with that name already exists.'));

            return $this->redirectToRoute('app_mcpserver_index', ['workspace' => $workspace->getId()]);
        }

        $server = new McpServer();
        $server->setWorkspace($workspace);
        $server->setName(mb_substr($name, 0, 150));
        $server->setUrl($url);
        $server->setDescription(trim((string) $request->request->get('description')) ?: null);
        $server->setHeaders($this->headersFromRequest($request));
        $this->servers->save($server);

        $this->refreshWith($server, $workspace, $request, $environments, $catalog, $translator);

        return $this->redirectToRoute('app_mcpserver_index', ['workspace' => $workspace->getId()]);
    }

    #[Route('/{server}/update', name: 'app_mcpserver_update', methods: ['POST'])]
    public function update(
        Workspace $workspace,
        #[MapEntity(mapping: ['server' => 'id'])] McpServer $server,
        Request $request,
        TranslatorInterface $translator,
    ): Response {
        $this->assertWorkspace($workspace, 'edit');
        $this->assertServer($workspace, $server);
        if (!$this->isCsrfTokenValid('edit-mcp-server' . $server->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $name = trim((string) $request->request->get('name'));
        if ('' !== $name) {
            $server->setName(mb_substr($name, 0, 150));
        }
        $url = trim((string) $request->request->get('url'));
        if ('' !== $url) {
            $server->setUrl($url);
        }
        $server->setDescription(trim((string) $request->request->get('description')) ?: null);
        $server->setHeaders($this->headersFromRequest($request));
        $this->servers->save($server);

        $this->addFlash('success', $translator->trans('MCP server saved.'));

        return $this->redirectToRoute('app_mcpserver_index', ['workspace' => $workspace->getId()]);
    }

    #[Route('/{server}/refresh', name: 'app_mcpserver_refresh', methods: ['POST'])]
    public function refresh(
        Workspace $workspace,
        #[MapEntity(mapping: ['server' => 'id'])] McpServer $server,
        Request $request,
        EnvironmentRepository $environments,
        McpCatalog $catalog,
        TranslatorInterface $translator,
    ): Response {
        $this->assertWorkspace($workspace, 'edit');
        $this->assertServer($workspace, $server);
        if (!$this->isCsrfTokenValid('refresh-mcp-server' . $server->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $this->refreshWith($server, $workspace, $request, $environments, $catalog, $translator);

        return $this->redirectToRoute('app_mcpserver_index', ['workspace' => $workspace->getId()]);
    }

    #[Route('/{server}/delete', name: 'app_mcpserver_delete', methods: ['POST'])]
    public function delete(
        Workspace $workspace,
        #[MapEntity(mapping: ['server' => 'id'])] McpServer $server,
        Request $request,
        TranslatorInterface $translator,
    ): Response {
        $this->assertWorkspace($workspace, 'edit');
        $this->assertServer($workspace, $server);
        if (!$this->isCsrfTokenValid('delete-mcp-server' . $server->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $this->servers->remove($server);
        $this->addFlash('success', $translator->trans('MCP server deleted. Steps that used it are kept, without a server.'));

        return $this->redirectToRoute('app_mcpserver_index', ['workspace' => $workspace->getId()]);
    }

    private function refreshWith(
        McpServer $server,
        Workspace $workspace,
        Request $request,
        EnvironmentRepository $environments,
        McpCatalog $catalog,
        TranslatorInterface $translator,
    ): void {
        $environment = null;
        $envId = (string) $request->request->get('environment');
        if ('' !== $envId) {
            $candidate = $environments->find($envId);
            if (null !== $candidate && $candidate->getWorkspace()->getId()?->toRfc4122() === $workspace->getId()?->toRfc4122()) {
                $environment = $candidate;
            }
        }

        $result = $catalog->refresh($server, $environment);
        if ($result['ok']) {
            $this->addFlash('success', $translator->trans('%count% tools read from the server.', ['%count%' => $result['tools']]));

            return;
        }

        $this->addFlash('error', $translator->trans('The server could not be reached: %error%', ['%error%' => (string) $result['error']]));
    }

    /**
     * @return array<string, string>
     */
    private function headersFromRequest(Request $request): array
    {
        $names = $request->request->all('header_name');
        $values = $request->request->all('header_value');
        $out = [];
        foreach ($names as $i => $name) {
            $name = trim((string) $name);
            if ('' !== $name) {
                $out[$name] = (string) ($values[$i] ?? '');
            }
        }

        return $out;
    }

    private function assertServer(Workspace $workspace, McpServer $server): void
    {
        if ($server->getWorkspace()->getId()?->toRfc4122() !== $workspace->getId()?->toRfc4122()) {
            throw $this->createNotFoundException();
        }
    }
}

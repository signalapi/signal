<?php

namespace App\Controller\App;

use App\Entity\Workspace;
use App\Repository\WorkspaceRepository;
use App\Security\MerchantVoter;
use App\Service\WorkspaceContext;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\Validator\Constraints as Assert;

#[Route('/app/workspaces')]
#[IsGranted('ROLE_USER')]
class WorkspaceController extends AbstractAppController
{
    #[Route('', name: 'app_workspace_index', methods: ['GET'])]
    public function index(WorkspaceContext $context): Response
    {
        return $this->render('app/workspace/index.html.twig', [
            'workspaces' => $context->list(),
        ]);
    }

    /** Workspace inventory is a company-admin concern: only owner / company admin. */
    #[Route('/new', name: 'app_workspace_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        WorkspaceRepository $workspaces,
        SluggerInterface $slugger,
    ): Response {
        $this->denyAccessUnlessGranted(MerchantVoter::MANAGE_WORKSPACES, $this->currentMerchant());

        $form = $this->createFormBuilder()
            ->add('name', TextType::class, [
                'label' => 'Workspace name',
                'constraints' => [new Assert\NotBlank()],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'required' => false,
            ])
            ->getForm();

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $merchant = $this->currentMerchant();

            $workspace = new Workspace();
            $workspace->setMerchant($merchant);
            $workspace->setName($data['name']);
            $workspace->setDescription($data['description'] ?? null);
            $workspace->setSlug($slugger->slug($data['name'])->lower() . '-' . substr(uniqid(), -5));
            $workspaces->save($workspace);

            $this->addFlash('success', $this->translator->trans('Workspace "%name%" created.', ['%name%' => $workspace->getName()]));

            return $this->redirectToRoute('app_workspace_show', ['id' => $workspace->getId()]);
        }

        return $this->render('app/workspace/new.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}', name: 'app_workspace_show', methods: ['GET'])]
    public function show(
        Workspace $workspace,
        \App\Repository\ApiTokenRepository $apiTokens,
        \App\Repository\FlowRunRepository $runs,
        \App\Service\WorkspaceContext $context,
        \App\Service\WorkspaceStats $stats,
        \App\Service\Mcp\McpToolRegistry $mcpTools,
    ): Response {
        $this->assertWorkspace($workspace);
        $context->remember($workspace);

        return $this->render('app/workspace/show.html.twig', [
            'workspace' => $workspace,
            'recent_runs' => $runs->recentForWorkspace($workspace, 6),
            'metrics' => $stats->metrics($workspace),
            'coverage' => $stats->coverage($workspace),
            'health' => $stats->health($workspace),
            'mcp_connected' => count($apiTokens->findByWorkspace($workspace)) > 0,
            'mcp_tools' => count($mcpTools->definitions()),
        ]);
    }

    /** Deleting a workspace is governance, not content admin: owner / company admin only. */
    #[Route('/{id}', name: 'app_workspace_delete', methods: ['POST'])]
    public function delete(Request $request, Workspace $workspace, WorkspaceRepository $workspaces): Response
    {
        $this->assertWorkspace($workspace);
        $this->denyAccessUnlessGranted(MerchantVoter::MANAGE_WORKSPACES, $workspace->getMerchant());

        if (!$this->isCsrfTokenValid('delete' . $workspace->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $workspaces->remove($workspace);
        $this->addFlash('success', $this->translator->trans('Workspace deleted.'));

        return $this->redirectToRoute('app_workspace_index');
    }

    /** Switches the active merchant; the workspace switcher then follows the new context. */
    #[Route('/switch-merchant/{id}', name: 'app_merchant_switch', methods: ['POST'])]
    public function switchMerchant(string $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('switch-merchant', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        foreach ($this->merchantContext->memberships() as $membership) {
            if ((string) $membership->getMerchant()->getId() === $id) {
                $this->merchantContext->remember($membership->getMerchant());

                return $this->redirectToRoute('app_dashboard');
            }
        }

        throw $this->createAccessDeniedException($this->translator->trans('You are not a member of this merchant.'));
    }
}

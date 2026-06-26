<?php

namespace App\Controller\App;

use App\Entity\User;
use App\Entity\Workspace;
use App\Repository\WorkspaceRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\Validator\Constraints as Assert;

#[Route('/app/workspaces')]
#[IsGranted('ROLE_MERCHANT')]
class WorkspaceController extends AbstractController
{
    #[Route('', name: 'app_workspace_index', methods: ['GET'])]
    public function index(WorkspaceRepository $workspaces): Response
    {
        return $this->render('app/workspace/index.html.twig', [
            'workspaces' => $workspaces->findByMerchant($this->currentMerchant()),
        ]);
    }

    #[Route('/new', name: 'app_workspace_new', methods: ['GET', 'POST'])]
    public function new(Request $request, WorkspaceRepository $workspaces, SluggerInterface $slugger): Response
    {
        $form = $this->createFormBuilder()
            ->add('name', TextType::class, [
                'label' => 'Workspace adı',
                'constraints' => [new Assert\NotBlank()],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Açıklama',
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

            $this->addFlash('success', sprintf('Workspace "%s" oluşturuldu.', $workspace->getName()));

            return $this->redirectToRoute('app_workspace_show', ['id' => $workspace->getId()]);
        }

        return $this->render('app/workspace/new.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}', name: 'app_workspace_show', methods: ['GET'])]
    public function show(
        Workspace $workspace,
        \App\Repository\ApiCollectionRepository $collections,
        \App\Repository\EnvironmentRepository $environments,
        \App\Repository\TestFlowRepository $flows,
        \App\Repository\DbConnectionRepository $dbConnections,
        \App\Repository\ApiTokenRepository $apiTokens,
        \App\Repository\FlowRunRepository $runs,
        \App\Service\WorkspaceContext $context,
    ): Response {
        $this->assertOwnership($workspace);
        $context->remember($workspace);

        return $this->render('app/workspace/show.html.twig', [
            'workspace' => $workspace,
            'collection_count' => count($collections->findByWorkspace($workspace)),
            'environment_count' => count($environments->findByWorkspace($workspace)),
            'flow_count' => count($flows->findByWorkspace($workspace)),
            'dbconn_count' => count($dbConnections->findByWorkspace($workspace)),
            'token_count' => count($apiTokens->findByWorkspace($workspace)),
            'recent_runs' => $runs->recentForWorkspace($workspace, 6),
        ]);
    }

    #[Route('/{id}', name: 'app_workspace_delete', methods: ['POST'])]
    public function delete(Request $request, Workspace $workspace, WorkspaceRepository $workspaces): Response
    {
        $this->assertOwnership($workspace);

        if (!$this->isCsrfTokenValid('delete' . $workspace->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $workspaces->remove($workspace);
        $this->addFlash('success', 'Workspace silindi.');

        return $this->redirectToRoute('app_workspace_index');
    }

    private function currentMerchant(): \App\Entity\Merchant
    {
        /** @var User $user */
        $user = $this->getUser();
        $merchant = $user->getMerchant();

        if (null === $merchant) {
            throw $this->createAccessDeniedException('Hesabınız bir merchant ile ilişkili değil.');
        }

        return $merchant;
    }

    private function assertOwnership(Workspace $workspace): void
    {
        if ($workspace->getMerchant()->getId()?->toRfc4122() !== $this->currentMerchant()->getId()?->toRfc4122()) {
            throw $this->createAccessDeniedException('Bu workspace size ait değil.');
        }
    }
}

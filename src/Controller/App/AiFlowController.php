<?php

namespace App\Controller\App;

use App\Entity\Workspace;
use App\Repository\ApiRequestRepository;
use App\Repository\EnvironmentRepository;
use App\Service\AiDiagnoser;
use App\Service\AiFlowArchitect;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Generate with AI": a plain-language brief in, a draft flow out. The result
 * always lands on the flow page for review — generation is a starting point,
 * not a finished test. Priority 1 so /flows/generate is matched before the
 * /flows/{flow} wildcard.
 */
#[Route('/app/workspaces/{workspace}/flows')]
#[IsGranted('ROLE_USER')]
class AiFlowController extends AbstractAppController
{
    #[Route('/generate', name: 'app_flow_generate', methods: ['GET', 'POST'], priority: 1)]
    public function generate(
        Workspace $workspace,
        Request $request,
        AiDiagnoser $ai,
        AiFlowArchitect $architect,
        EnvironmentRepository $environments,
        ApiRequestRepository $requests,
        TranslatorInterface $translator,
    ): Response {
        $this->assertWorkspace($workspace, 'edit');

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('flow-generate', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException();
            }

            $brief = trim((string) $request->request->get('brief'));
            if (mb_strlen($brief) < 10) {
                $this->addFlash('error', $translator->trans('Describe the scenario in at least a sentence.'));

                return $this->redirectToRoute('app_flow_generate', ['workspace' => $workspace->getId()]);
            }
            if (!$ai->isConfigured()) {
                $this->addFlash('error', $translator->trans('AI is not connected. A platform admin can add an Anthropic API key under Admin → Settings.'));

                return $this->redirectToRoute('app_flow_generate', ['workspace' => $workspace->getId()]);
            }

            $environment = null;
            $environmentId = (string) $request->request->get('environment');
            if (Uuid::isValid($environmentId)) {
                $environment = $environments->find(Uuid::fromString($environmentId));
                if (null !== $environment && $environment->getWorkspace()->getId()?->toRfc4122() !== $workspace->getId()?->toRfc4122()) {
                    throw $this->createNotFoundException();
                }
            }

            try {
                $result = $architect->generate($workspace, $brief, $environment, $request->getLocale());
            } catch (\Throwable $e) {
                $this->addFlash('error', $translator->trans('Generation failed: %error%', ['%error%' => $e->getMessage()]));

                return $this->redirectToRoute('app_flow_generate', ['workspace' => $workspace->getId()]);
            }

            $this->addFlash('success', $translator->trans('Claude drafted "%name%" with %count% steps. Review every step before the first run.', [
                '%name%' => $result['flow']->getName(),
                '%count%' => $result['stepCount'],
            ]));
            foreach (\array_slice($result['warnings'], 0, 5) as $warning) {
                $this->addFlash('error', $warning);
            }

            return $this->redirectToRoute('app_flow_show', [
                'workspace' => $workspace->getId(),
                'flow' => $result['flow']->getId(),
            ]);
        }

        return $this->render('app/flow/generate.html.twig', [
            'workspace' => $workspace,
            'environments' => $environments->findByWorkspace($workspace),
            'configured' => $ai->isConfigured(),
            'catalogSize' => \count($requests->findByWorkspace($workspace)),
        ]);
    }
}

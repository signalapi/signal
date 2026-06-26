<?php

namespace App\Controller\App;

use App\Entity\ApiToken;
use App\Entity\User;
use App\Entity\Workspace;
use App\Repository\ApiTokenRepository;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/app/workspaces/{workspace}/api-tokens')]
#[IsGranted('ROLE_MERCHANT')]
class ApiTokenController extends AbstractAppController
{
    #[Route('', name: 'app_apitoken_index', methods: ['GET'])]
    public function index(Workspace $workspace, Request $httpRequest, ApiTokenRepository $tokens): Response
    {
        $this->assertWorkspace($workspace);

        // Consume the one-time plaintext before the layout renders generic flashes.
        $new = $httpRequest->getSession()->getFlashBag()->get('new_token');

        return $this->render('app/apitoken/index.html.twig', [
            'workspace' => $workspace,
            'tokens' => $tokens->findByWorkspace($workspace),
            'new_token' => $new[0] ?? null,
        ]);
    }

    #[Route('/new', name: 'app_apitoken_new', methods: ['POST'])]
    public function new(Workspace $workspace, Request $httpRequest, ApiTokenRepository $tokens): Response
    {
        $this->assertWorkspace($workspace);

        if (!$this->isCsrfTokenValid('apitoken-new', (string) $httpRequest->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $plain = 'apit_' . bin2hex(random_bytes(24));

        /** @var User $user */
        $user = $this->getUser();

        $token = new ApiToken();
        $token->setWorkspace($workspace);
        $token->setCreatedBy($user);
        $token->setName(trim((string) $httpRequest->request->get('name')) ?: 'token');
        $token->setTokenHash(hash('sha256', $plain));
        $token->setTokenPrefix(substr($plain, 0, 12));
        $tokens->save($token);

        // Show the plaintext exactly once.
        $httpRequest->getSession()->getFlashBag()->add('new_token', $plain);
        $this->addFlash('success', 'Token oluşturuldu. Aşağıdaki değeri şimdi kopyalayın — tekrar gösterilmeyecek.');

        return $this->redirectToRoute('app_apitoken_index', ['workspace' => $workspace->getId()]);
    }

    #[Route('/{token}/revoke', name: 'app_apitoken_revoke', methods: ['POST'])]
    public function revoke(
        Workspace $workspace,
        #[MapEntity(mapping: ['token' => 'id'])] ApiToken $token,
        Request $httpRequest,
        ApiTokenRepository $tokens,
    ): Response {
        $this->assertWorkspace($workspace);
        if ($token->getWorkspace()->getId()?->toRfc4122() !== $workspace->getId()?->toRfc4122()) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('revoke' . $token->getId(), (string) $httpRequest->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $token->setRevoked(true);
        $tokens->save($token);
        $this->addFlash('success', 'Token iptal edildi.');

        return $this->redirectToRoute('app_apitoken_index', ['workspace' => $workspace->getId()]);
    }
}

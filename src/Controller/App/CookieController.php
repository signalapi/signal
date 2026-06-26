<?php

namespace App\Controller\App;

use App\Entity\Cookie;
use App\Entity\Workspace;
use App\Repository\CookieRepository;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/app/workspaces/{workspace}/cookies')]
#[IsGranted('ROLE_MERCHANT')]
class CookieController extends AbstractAppController
{
    #[Route('', name: 'app_cookie_index', methods: ['GET'])]
    public function index(Workspace $workspace, CookieRepository $cookies): Response
    {
        $this->assertWorkspace($workspace);

        return $this->render('app/cookie/index.html.twig', [
            'workspace' => $workspace,
            'cookies' => $cookies->findByWorkspace($workspace),
        ]);
    }

    #[Route('/clear', name: 'app_cookie_clear', methods: ['POST'])]
    public function clear(Workspace $workspace, Request $httpRequest, CookieRepository $cookies): Response
    {
        $this->assertWorkspace($workspace);
        if (!$this->isCsrfTokenValid('cookie-clear', (string) $httpRequest->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $cookies->clearWorkspace($workspace);
        $this->addFlash('success', 'Tüm cookie\'ler temizlendi.');

        return $this->redirectToRoute('app_cookie_index', ['workspace' => $workspace->getId()]);
    }

    #[Route('/{cookie}/delete', name: 'app_cookie_delete', methods: ['POST'])]
    public function delete(
        Workspace $workspace,
        #[MapEntity(mapping: ['cookie' => 'id'])] Cookie $cookie,
        Request $httpRequest,
        CookieRepository $cookies,
    ): Response {
        $this->assertWorkspace($workspace);
        if ($cookie->getWorkspace()->getId()?->toRfc4122() !== $workspace->getId()?->toRfc4122()) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('delete' . $cookie->getId(), (string) $httpRequest->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $cookies->remove($cookie);
        $this->addFlash('success', 'Cookie silindi.');

        return $this->redirectToRoute('app_cookie_index', ['workspace' => $workspace->getId()]);
    }
}

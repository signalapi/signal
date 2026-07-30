<?php

namespace App\Controller;

use App\Entity\User;
use App\EventListener\LocaleListener;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Language switcher: remembers the choice in the session (works for anonymous
 * pages too) and persists it as the user's preference when logged in.
 */
class LocaleController extends AbstractController
{
    #[Route('/locale/{locale}', name: 'app_locale_switch', methods: ['GET'])]
    public function switch(string $locale, Request $request, EntityManagerInterface $em): Response
    {
        if (!\in_array($locale, LocaleListener::LOCALES, true)) {
            throw $this->createNotFoundException();
        }

        $request->getSession()->set(LocaleListener::SESSION_KEY, $locale);

        $user = $this->getUser();
        if ($user instanceof User) {
            $user->setLocale($locale);
            $em->flush();
        }

        // Back to where the user was; same-host referers only.
        $referer = (string) $request->headers->get('referer');
        $host = parse_url($referer, \PHP_URL_HOST);
        if ('' !== $referer && ($host === null || $host === $request->getHost())) {
            return $this->redirect($referer);
        }

        return $this->redirectToRoute('app_dashboard');
    }
}

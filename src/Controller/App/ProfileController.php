<?php

namespace App\Controller\App;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/app/profile')]
#[IsGranted('ROLE_USER')]
class ProfileController extends AbstractAppController
{
    #[Route('', name: 'app_profile', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('app/profile.html.twig', [
            'memberships' => $this->merchantContext->memberships(),
        ]);
    }

    #[Route('/name', name: 'app_profile_name', methods: ['POST'])]
    public function updateName(Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('profile-name', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $name = trim((string) $request->request->get('name'));
        if ('' === $name) {
            $this->addFlash('error', $this->translator->trans('Full name cannot be empty.'));

            return $this->redirectToRoute('app_profile');
        }

        $this->currentUser()->setName($name);
        $em->flush();
        $this->addFlash('success', $this->translator->trans('Your name has been updated.'));

        return $this->redirectToRoute('app_profile');
    }

    #[Route('/password', name: 'app_profile_password', methods: ['POST'])]
    public function changePassword(
        Request $request,
        UserPasswordHasherInterface $hasher,
        EntityManagerInterface $em,
    ): Response {
        if (!$this->isCsrfTokenValid('profile-password', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $user = $this->currentUser();
        $current = (string) $request->request->get('current_password');
        $new = (string) $request->request->get('new_password');
        $confirm = (string) $request->request->get('new_password_confirm');

        if (!$hasher->isPasswordValid($user, $current)) {
            $this->addFlash('error', $this->translator->trans('Current password is incorrect.'));
        } elseif (mb_strlen($new) < 8) {
            $this->addFlash('error', $this->translator->trans('The new password must be at least 8 characters.'));
        } elseif ($new !== $confirm) {
            $this->addFlash('error', $this->translator->trans('The new passwords do not match.'));
        } else {
            $user->setPassword($hasher->hashPassword($user, $new));
            $em->flush();
            $this->addFlash('success', $this->translator->trans('Your password has been changed.'));
        }

        return $this->redirectToRoute('app_profile');
    }
}

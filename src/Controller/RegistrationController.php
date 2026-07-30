<?php

namespace App\Controller;

use App\Entity\Merchant;
use App\Entity\MerchantMember;
use App\Entity\User;
use App\Repository\MerchantRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class RegistrationController extends AbstractController
{
    #[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(
        Request $request,
        MerchantRepository $merchants,
        UserRepository $users,
        UserPasswordHasherInterface $hasher,
        SluggerInterface $slugger,
        Security $security,
        EntityManagerInterface $em,
        TranslatorInterface $translator,
    ): Response {
        if ($this->isGranted('IS_AUTHENTICATED_FULLY')) {
            return $this->redirectToRoute('app_dashboard');
        }

        $errors = [];
        $old = ['name' => '', 'email' => ''];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('register', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException();
            }

            $old['name'] = trim((string) $request->request->get('name'));
            $old['email'] = mb_strtolower(trim((string) $request->request->get('email')));
            $password = (string) $request->request->get('password');
            $confirm = (string) $request->request->get('password_confirm');

            if ('' === $old['name']) {
                $errors[] = $translator->trans('Full name is required.');
            }
            if (!filter_var($old['email'], \FILTER_VALIDATE_EMAIL)) {
                $errors[] = $translator->trans('Enter a valid e-mail address.');
            } elseif ($users->findOneBy(['email' => $old['email']])) {
                $errors[] = $translator->trans('This e-mail is already registered.');
            }
            if (mb_strlen($password) < 8) {
                $errors[] = $translator->trans('Password must be at least 8 characters.');
            } elseif ($password !== $confirm) {
                $errors[] = $translator->trans('Passwords do not match.');
            }

            if ([] === $errors) {
                // Sign-up creates a personal account: no company name is asked
                // for, and the UI hides the company vocabulary until the owner
                // invites someone. Everything still hangs off a merchant.
                $merchant = new Merchant();
                $merchant->setName($old['name']);
                $merchant->setSlug($slugger->slug($old['name'])->lower() . '-' . substr(uniqid(), -5));
                $merchant->setPersonal(true);
                $merchants->save($merchant, false);

                $user = new User();
                $user->setName($old['name']);
                $user->setEmail($old['email']);
                $user->setPassword($hasher->hashPassword($user, $password));
                $users->save($user, false);

                $membership = new MerchantMember();
                $membership->setMerchant($merchant);
                $membership->setUser($user);
                $membership->setRole(MerchantMember::ROLE_OWNER);
                $em->persist($membership);
                $em->flush();

                // Log the new owner straight into the merchant firewall.
                $security->login($user, 'form_login', 'main');
                $this->addFlash('success', $translator->trans('Welcome to Signal! Create a workspace to get started.'));

                return $this->redirectToRoute('app_dashboard');
            }
        }

        return $this->render('security/register.html.twig', [
            'errors' => $errors,
            'old' => $old,
        ]);
    }
}

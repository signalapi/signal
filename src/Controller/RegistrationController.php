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
    ): Response {
        if ($this->isGranted('IS_AUTHENTICATED_FULLY')) {
            return $this->redirectToRoute('app_dashboard');
        }

        $errors = [];
        $old = ['merchant' => '', 'name' => '', 'email' => ''];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('register', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException();
            }

            $old['merchant'] = trim((string) $request->request->get('merchant'));
            $old['name'] = trim((string) $request->request->get('name'));
            $old['email'] = mb_strtolower(trim((string) $request->request->get('email')));
            $password = (string) $request->request->get('password');
            $confirm = (string) $request->request->get('password_confirm');

            if ('' === $old['merchant']) {
                $errors[] = 'Şirket/merchant adı gerekli.';
            }
            if ('' === $old['name']) {
                $errors[] = 'Ad soyad gerekli.';
            }
            if (!filter_var($old['email'], \FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Geçerli bir e-posta girin.';
            } elseif ($users->findOneBy(['email' => $old['email']])) {
                $errors[] = 'Bu e-posta zaten kayıtlı.';
            }
            if (mb_strlen($password) < 8) {
                $errors[] = 'Şifre en az 8 karakter olmalı.';
            } elseif ($password !== $confirm) {
                $errors[] = 'Şifreler eşleşmiyor.';
            }

            if ([] === $errors) {
                $merchant = new Merchant();
                $merchant->setName($old['merchant']);
                $merchant->setSlug($slugger->slug($old['merchant'])->lower() . '-' . substr(uniqid(), -5));
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

                // Log the new merchant admin straight into the merchant firewall.
                $security->login($user, 'form_login', 'main');
                $this->addFlash('success', sprintf('Hoş geldiniz! "%s" hesabı oluşturuldu.', $merchant->getName()));

                return $this->redirectToRoute('app_dashboard');
            }
        }

        return $this->render('security/register.html.twig', [
            'errors' => $errors,
            'old' => $old,
        ]);
    }
}

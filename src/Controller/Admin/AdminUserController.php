<?php

namespace App\Controller\Admin;

use App\Entity\AdminUser;
use App\Repository\AdminUserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Platform admin management: list, invite, reset passwords, deactivate.
 * Guard rails: you cannot delete or deactivate yourself, and the last
 * active admin can never be removed — there is no other way back in.
 */
#[Route('/admin/admins')]
#[IsGranted('ROLE_SUPER_ADMIN')]
class AdminUserController extends AbstractController
{
    public function __construct(
        private readonly AdminUserRepository $admins,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'admin_admin_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/admins.html.twig', [
            'admins' => $this->admins->findBy([], ['createdAt' => 'ASC']),
        ]);
    }

    #[Route('', name: 'admin_admin_new', methods: ['POST'])]
    public function new(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('admin-new', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $email = mb_strtolower(trim((string) $request->request->get('email')));
        $name = trim((string) $request->request->get('name'));
        $password = (string) $request->request->get('password');

        if (!filter_var($email, \FILTER_VALIDATE_EMAIL) || '' === $name) {
            $this->addFlash('error', $this->translator->trans('A valid email and a name are required.'));

            return $this->redirectToRoute('admin_admin_index');
        }
        if ($this->admins->findOneBy(['email' => $email])) {
            $this->addFlash('error', $this->translator->trans('An admin with this email already exists.'));

            return $this->redirectToRoute('admin_admin_index');
        }

        $generated = '' === $password;
        if ($generated) {
            $password = bin2hex(random_bytes(9));
        } elseif (mb_strlen($password) < 8) {
            $this->addFlash('error', $this->translator->trans('Password must be at least 8 characters.'));

            return $this->redirectToRoute('admin_admin_index');
        }

        $admin = new AdminUser();
        $admin->setEmail($email);
        $admin->setName($name);
        $admin->setPassword($this->hasher->hashPassword($admin, $password));
        $this->admins->save($admin);

        $this->addFlash('success', $generated
            ? $this->translator->trans('Admin created. One-time password (share it now, it is not shown again): %password%', ['%password%' => $password])
            : $this->translator->trans('Admin created.'));

        return $this->redirectToRoute('admin_admin_index');
    }

    #[Route('/{id}/password', name: 'admin_admin_password', methods: ['POST'])]
    public function password(AdminUser $admin, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('admin-password' . $admin->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $password = (string) $request->request->get('password');
        $generated = '' === $password;
        if ($generated) {
            $password = bin2hex(random_bytes(9));
        } elseif (mb_strlen($password) < 8) {
            $this->addFlash('error', $this->translator->trans('Password must be at least 8 characters.'));

            return $this->redirectToRoute('admin_admin_index');
        }

        $admin->setPassword($this->hasher->hashPassword($admin, $password));
        $this->admins->save($admin);

        $this->addFlash('success', $generated
            ? $this->translator->trans('New password for %email% (shown only once): %password%', ['%email%' => $admin->getEmail(), '%password%' => $password])
            : $this->translator->trans('Password updated for %email%.', ['%email%' => $admin->getEmail()]));

        return $this->redirectToRoute('admin_admin_index');
    }

    #[Route('/{id}/toggle', name: 'admin_admin_toggle', methods: ['POST'])]
    public function toggle(AdminUser $admin, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('admin-toggle' . $admin->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        if ($this->isSelf($admin)) {
            $this->addFlash('error', $this->translator->trans('You cannot deactivate your own account.'));

            return $this->redirectToRoute('admin_admin_index');
        }
        if ($admin->isActive() && $this->isLastActive($admin)) {
            $this->addFlash('error', $this->translator->trans('The last active admin cannot be deactivated.'));

            return $this->redirectToRoute('admin_admin_index');
        }

        $admin->setActive(!$admin->isActive());
        $this->admins->save($admin);
        $this->addFlash('success', $admin->isActive()
            ? $this->translator->trans('%email% activated.', ['%email%' => $admin->getEmail()])
            : $this->translator->trans('%email% deactivated.', ['%email%' => $admin->getEmail()]));

        return $this->redirectToRoute('admin_admin_index');
    }

    #[Route('/{id}/delete', name: 'admin_admin_delete', methods: ['POST'])]
    public function delete(AdminUser $admin, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('admin-delete' . $admin->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        if ($this->isSelf($admin)) {
            $this->addFlash('error', $this->translator->trans('You cannot delete your own account.'));

            return $this->redirectToRoute('admin_admin_index');
        }
        if ($admin->isActive() && $this->isLastActive($admin)) {
            $this->addFlash('error', $this->translator->trans('The last active admin cannot be deleted.'));

            return $this->redirectToRoute('admin_admin_index');
        }

        $email = $admin->getEmail();
        $this->admins->remove($admin);
        $this->addFlash('success', $this->translator->trans('%email% deleted.', ['%email%' => $email]));

        return $this->redirectToRoute('admin_admin_index');
    }

    private function isSelf(AdminUser $admin): bool
    {
        $me = $this->getUser();

        return $me instanceof AdminUser && $me->getUserIdentifier() === $admin->getUserIdentifier();
    }

    private function isLastActive(AdminUser $admin): bool
    {
        return 1 === \count($this->admins->findBy(['active' => true]));
    }
}

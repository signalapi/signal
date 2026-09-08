<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Forgot/reset password. The classic hygiene rules apply: the answer to "send a
 * link" is always the same whether the address exists or not, only the sha256
 * of the token is stored (the token itself lives in the e-mail alone), links
 * die after an hour or on first use, and a successful reset clears the token.
 */
class PasswordResetController extends AbstractController
{
    private const TOKEN_TTL = '+1 hour';

    public function __construct(
        #[Autowire(env: 'MAILER_FROM')] private readonly string $from = '',
    ) {
    }

    #[Route('/forgot', name: 'app_forgot_password', methods: ['GET', 'POST'])]
    public function forgot(
        Request $request,
        UserRepository $users,
        EntityManagerInterface $em,
        MailerInterface $mailer,
        TranslatorInterface $translator,
    ): Response {
        if ($this->isGranted('IS_AUTHENTICATED_FULLY')) {
            return $this->redirectToRoute('app_dashboard');
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('forgot-password', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException();
            }

            $email = mb_strtolower(trim((string) $request->request->get('email')));
            $user = filter_var($email, \FILTER_VALIDATE_EMAIL) ? $users->findOneBy(['email' => $email]) : null;

            if (null !== $user && $user->isActive()) {
                $token = bin2hex(random_bytes(32));
                $user->setResetTokenHash(hash('sha256', $token));
                $user->setResetTokenExpiresAt(new \DateTimeImmutable(self::TOKEN_TTL));
                $em->flush();

                $link = $this->generateUrl('app_reset_password', ['token' => $token], \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL);
                $mailer->send((new Email())
                    ->from('' !== trim($this->from) ? trim($this->from) : 'Signal <no-reply@' . $request->getHost() . '>')
                    ->to($user->getEmail())
                    ->subject($translator->trans('Reset your Signal password'))
                    ->text($translator->trans(
                        "Hi %name%,\n\nSomeone (hopefully you) asked to reset the password for this Signal account.\n\nSet a new one here (the link works once and expires in an hour):\n%link%\n\nIf this wasn't you, ignore this e-mail — nothing changes.",
                        ['%name%' => $user->getName(), '%link%' => $link],
                    )));
            }

            // Same answer either way — the form never confirms whether an
            // address has an account.
            $this->addFlash('success', $translator->trans('If that address has an account, a reset link is on its way. Check your inbox.'));

            return $this->redirectToRoute('app_forgot_password');
        }

        return $this->render('security/forgot.html.twig');
    }

    #[Route('/reset/{token}', name: 'app_reset_password', requirements: ['token' => '[a-f0-9]{64}'], methods: ['GET', 'POST'])]
    public function reset(
        string $token,
        Request $request,
        UserRepository $users,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher,
        TranslatorInterface $translator,
    ): Response {
        $user = $users->findOneBy(['resetTokenHash' => hash('sha256', $token)]);
        if (null === $user
            || null === $user->getResetTokenExpiresAt()
            || $user->getResetTokenExpiresAt() < new \DateTimeImmutable()) {
            $this->addFlash('error', $translator->trans('This reset link is invalid or has expired. Request a new one.'));

            return $this->redirectToRoute('app_forgot_password');
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('reset-password', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException();
            }

            $password = (string) $request->request->get('password');
            $confirm = (string) $request->request->get('password_confirm');
            if (mb_strlen($password) < 8) {
                $this->addFlash('error', $translator->trans('Password must be at least 8 characters.'));
            } elseif ($password !== $confirm) {
                $this->addFlash('error', $translator->trans('Passwords do not match.'));
            } else {
                $user->setPassword($hasher->hashPassword($user, $password));
                $user->setResetTokenHash(null);
                $user->setResetTokenExpiresAt(null);
                $em->flush();
                $this->addFlash('success', $translator->trans('Password updated — sign in with the new one.'));

                return $this->redirectToRoute('app_login');
            }
        }

        return $this->render('security/reset.html.twig', ['token' => $token]);
    }
}

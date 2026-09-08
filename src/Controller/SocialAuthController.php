<?php

namespace App\Controller;

use App\Entity\Merchant;
use App\Entity\MerchantMember;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Continue with Google / GitHub" — a hand-rolled OAuth 2.0 code flow (state in
 * the session, code→token→profile over HttpClient), deliberately dependency-free.
 *
 * Only VERIFIED provider e-mails are accepted; a matching Signal account logs
 * straight in, an unknown one gets the same personal-account setup as the
 * register form (merchant + owner membership, random password — the person can
 * set one later, or keep signing in socially).
 */
class SocialAuthController extends AbstractController
{
    public function __construct(
        private readonly HttpClientInterface $http,
        #[Autowire(env: 'OAUTH_GOOGLE_CLIENT_ID')] private readonly string $googleId = '',
        #[Autowire(env: 'OAUTH_GOOGLE_CLIENT_SECRET')] private readonly string $googleSecret = '',
        #[Autowire(env: 'OAUTH_GITHUB_CLIENT_ID')] private readonly string $githubId = '',
        #[Autowire(env: 'OAUTH_GITHUB_CLIENT_SECRET')] private readonly string $githubSecret = '',
    ) {
    }

    public static function enabledProviders(string $googleId, string $githubId): array
    {
        return array_keys(array_filter(['google' => '' !== trim($googleId), 'github' => '' !== trim($githubId)]));
    }

    #[Route('/oauth/{provider}/start', name: 'oauth_start', requirements: ['provider' => 'google|github'], methods: ['GET'])]
    public function start(string $provider, Request $request): Response
    {
        if (!$this->configured($provider)) {
            throw $this->createNotFoundException();
        }

        $state = bin2hex(random_bytes(16));
        $request->getSession()->set('oauth_state', $state);
        $redirect = $this->generateUrl('oauth_callback', ['provider' => $provider], \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL);

        $url = 'google' === $provider
            ? 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
                'client_id' => trim($this->googleId),
                'redirect_uri' => $redirect,
                'response_type' => 'code',
                'scope' => 'openid email profile',
                'state' => $state,
            ])
            : 'https://github.com/login/oauth/authorize?' . http_build_query([
                'client_id' => trim($this->githubId),
                'redirect_uri' => $redirect,
                'scope' => 'read:user user:email',
                'state' => $state,
            ]);

        return $this->redirect($url);
    }

    #[Route('/oauth/{provider}/callback', name: 'oauth_callback', requirements: ['provider' => 'google|github'], methods: ['GET'])]
    public function callback(
        string $provider,
        Request $request,
        UserRepository $users,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher,
        SluggerInterface $slugger,
        Security $security,
        TranslatorInterface $translator,
    ): Response {
        if (!$this->configured($provider)) {
            throw $this->createNotFoundException();
        }

        $state = (string) $request->query->get('state');
        $known = (string) $request->getSession()->get('oauth_state');
        $request->getSession()->remove('oauth_state');
        $code = (string) $request->query->get('code');
        if ('' === $code || '' === $known || !hash_equals($known, $state)) {
            $this->addFlash('error', $translator->trans('Sign-in was cancelled or the session expired — try again.'));

            return $this->redirectToRoute('app_login');
        }

        try {
            $profile = $this->profile($provider, $code, $this->generateUrl('oauth_callback', ['provider' => $provider], \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL));
        } catch (\Throwable $e) {
            $this->addFlash('error', $translator->trans('%provider% sign-in failed: %error%', [
                '%provider%' => ucfirst($provider), '%error%' => $e->getMessage(),
            ]));

            return $this->redirectToRoute('app_login');
        }

        $email = mb_strtolower(trim($profile['email']));
        $user = $users->findOneBy(['email' => $email]);

        if (null === $user) {
            // Same shape as the register form: a personal account.
            $name = '' !== trim($profile['name']) ? trim($profile['name']) : explode('@', $email)[0];
            $merchant = new Merchant();
            $merchant->setName($name);
            $merchant->setSlug($slugger->slug($name)->lower() . '-' . substr(uniqid(), -5));
            $merchant->setPersonal(true);
            $em->persist($merchant);

            $user = new User();
            $user->setName($name);
            $user->setEmail($email);
            $user->setPassword($hasher->hashPassword($user, bin2hex(random_bytes(24))));
            $em->persist($user);

            $membership = new MerchantMember();
            $membership->setMerchant($merchant);
            $membership->setUser($user);
            $membership->setRole(MerchantMember::ROLE_OWNER);
            $em->persist($membership);
            $em->flush();

            $this->addFlash('success', $translator->trans('Welcome to Signal! Create a workspace to get started.'));
        }

        if (method_exists($user, 'isActive') && !$user->isActive()) {
            $this->addFlash('error', $translator->trans('This account is deactivated.'));

            return $this->redirectToRoute('app_login');
        }

        $security->login($user, 'form_login', 'main');

        return $this->redirectToRoute('app_dashboard');
    }

    /**
     * code → access token → {email (verified only), name}. Throws with a plain
     * message on any refusal; the caller turns it into a flash.
     *
     * @return array{email: string, name: string}
     */
    private function profile(string $provider, string $code, string $redirectUri): array
    {
        if ('google' === $provider) {
            $token = $this->http->request('POST', 'https://oauth2.googleapis.com/token', [
                'body' => [
                    'client_id' => trim($this->googleId),
                    'client_secret' => trim($this->googleSecret),
                    'code' => $code,
                    'grant_type' => 'authorization_code',
                    'redirect_uri' => $redirectUri,
                ],
                'timeout' => 15,
            ])->toArray(false);
            $access = (string) ($token['access_token'] ?? '');
            if ('' === $access) {
                throw new \RuntimeException((string) ($token['error_description'] ?? $token['error'] ?? 'no access token'));
            }

            $info = $this->http->request('GET', 'https://openidconnect.googleapis.com/v1/userinfo', [
                'auth_bearer' => $access,
                'timeout' => 15,
            ])->toArray(false);
            $email = (string) ($info['email'] ?? '');
            if ('' === $email || true !== ($info['email_verified'] ?? false)) {
                throw new \RuntimeException('the Google account has no verified e-mail');
            }

            return ['email' => $email, 'name' => (string) ($info['name'] ?? '')];
        }

        $token = $this->http->request('POST', 'https://github.com/login/oauth/access_token', [
            'headers' => ['Accept' => 'application/json'],
            'body' => [
                'client_id' => trim($this->githubId),
                'client_secret' => trim($this->githubSecret),
                'code' => $code,
                'redirect_uri' => $redirectUri,
            ],
            'timeout' => 15,
        ])->toArray(false);
        $access = (string) ($token['access_token'] ?? '');
        if ('' === $access) {
            throw new \RuntimeException((string) ($token['error_description'] ?? $token['error'] ?? 'no access token'));
        }

        $headers = ['auth_bearer' => $access, 'headers' => ['User-Agent' => 'Signal-App'], 'timeout' => 15];
        $ghUser = $this->http->request('GET', 'https://api.github.com/user', $headers)->toArray(false);

        $email = '';
        foreach ($this->http->request('GET', 'https://api.github.com/user/emails', $headers)->toArray(false) as $row) {
            if (\is_array($row) && ($row['verified'] ?? false) && ($row['primary'] ?? false)) {
                $email = (string) $row['email'];
                break;
            }
        }
        if ('' === $email) {
            throw new \RuntimeException('the GitHub account has no verified primary e-mail');
        }

        return ['email' => $email, 'name' => (string) ($ghUser['name'] ?? ($ghUser['login'] ?? ''))];
    }

    private function configured(string $provider): bool
    {
        return 'google' === $provider
            ? '' !== trim($this->googleId) && '' !== trim($this->googleSecret)
            : '' !== trim($this->githubId) && '' !== trim($this->githubSecret);
    }
}

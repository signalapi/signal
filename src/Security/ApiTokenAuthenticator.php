<?php

namespace App\Security;

use App\Repository\ApiTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Authenticates /api requests via "Authorization: Bearer <token>". The matched
 * ApiToken (and thus its workspace) is stashed on the request for controllers.
 */
class ApiTokenAuthenticator extends AbstractAuthenticator
{
    public const REQUEST_ATTR = '_api_token';

    public function __construct(
        private readonly ApiTokenRepository $tokens,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        $path = $request->getPathInfo();

        return str_starts_with($path, '/api/') || str_starts_with($path, '/mcp');
    }

    public function authenticate(Request $request): Passport
    {
        $header = (string) $request->headers->get('Authorization', '');
        if (!preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
            throw new CustomUserMessageAuthenticationException('Authorization: Bearer <token> header is required.');
        }

        $apiToken = $this->tokens->findActiveByHash(hash('sha256', $m[1]));
        if (null === $apiToken) {
            throw new CustomUserMessageAuthenticationException('Invalid or revoked API token.');
        }

        $owner = $apiToken->getCreatedBy();
        if (null === $owner) {
            throw new CustomUserMessageAuthenticationException('The user who owns this token has been deleted.');
        }

        $apiToken->setLastUsedAt(new \DateTimeImmutable());
        $this->em->flush();
        $request->attributes->set(self::REQUEST_ATTR, $apiToken);

        return new SelfValidatingPassport(new UserBadge($owner->getUserIdentifier()));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new JsonResponse(['ok' => false, 'error' => $exception->getMessageKey()], Response::HTTP_UNAUTHORIZED);
    }
}

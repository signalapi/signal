<?php

namespace App\Service;

use App\Entity\Cookie;
use App\Entity\User;
use App\Entity\Workspace;
use App\Repository\CookieRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A per-(workspace, user) cookie jar: applies stored cookies to outgoing
 * requests and captures Set-Cookie headers from responses (Postman-style
 * automatic cookies). A null user is the shared jar used by automated flow
 * runs, which have no acting user.
 */
class CookieJar
{
    public function __construct(
        private readonly CookieRepository $cookies,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Builds the "Cookie:" header value for a URL, or null if no cookie matches.
     */
    public function cookieHeader(string $url, Workspace $workspace, ?User $user = null): ?string
    {
        $host = (string) parse_url($url, \PHP_URL_HOST);
        if ('' === $host) {
            return null;
        }
        $path = parse_url($url, \PHP_URL_PATH) ?: '/';
        $secureCtx = 'https' === parse_url($url, \PHP_URL_SCHEME);
        $now = new \DateTimeImmutable();

        $pairs = [];
        foreach ($this->cookies->findByWorkspace($workspace, $user) as $cookie) {
            if ($cookie->isExpired($now) || !$this->domainMatches($host, $cookie) || !str_starts_with($path, $cookie->getPath())) {
                continue;
            }
            if ($cookie->isSecure() && !$secureCtx) {
                continue;
            }
            $pairs[] = $cookie->getName() . '=' . $cookie->getValue();
        }

        return [] === $pairs ? null : implode('; ', $pairs);
    }

    /**
     * Stores/updates cookies from a response's Set-Cookie headers.
     *
     * @param string[] $setCookieHeaders
     */
    public function storeFromResponse(array $setCookieHeaders, string $url, Workspace $workspace, ?User $user = null): void
    {
        if ([] === $setCookieHeaders) {
            return;
        }

        $host = (string) parse_url($url, \PHP_URL_HOST);
        if ('' === $host) {
            return;
        }
        $now = new \DateTimeImmutable();
        $changed = false;

        foreach ($setCookieHeaders as $header) {
            $parsed = $this->parseSetCookie((string) $header, $host);
            if (null === $parsed) {
                continue;
            }

            $existing = $this->cookies->findOneMatch($workspace, $user, $parsed['domain'], $parsed['path'], $parsed['name']);

            // Expired cookie -> delete if present, otherwise ignore.
            if (null !== $parsed['expiresAt'] && $parsed['expiresAt'] <= $now) {
                if (null !== $existing) {
                    $this->em->remove($existing);
                    $changed = true;
                }
                continue;
            }

            $cookie = $existing ?? new Cookie();
            if (null === $existing) {
                $cookie->setWorkspace($workspace);
                $cookie->setUser($user);
                $cookie->setDomain($parsed['domain']);
                $cookie->setPath($parsed['path']);
                $cookie->setName($parsed['name']);
                $this->em->persist($cookie);
            }
            $cookie->setValue($parsed['value']);
            $cookie->setExpiresAt($parsed['expiresAt']);
            $cookie->setSecure($parsed['secure']);
            $cookie->setHostOnly($parsed['hostOnly']);
            $cookie->touch();
            $changed = true;
        }

        if ($changed) {
            $this->em->flush();
        }
    }

    private function domainMatches(string $host, Cookie $cookie): bool
    {
        $domain = $cookie->getDomain();
        if ($cookie->isHostOnly()) {
            return $host === $domain;
        }

        return $host === $domain || str_ends_with($host, '.' . $domain);
    }

    /**
     * @return array{name: string, value: string, domain: string, path: string, expiresAt: ?\DateTimeImmutable, secure: bool, hostOnly: bool}|null
     */
    private function parseSetCookie(string $header, string $host): ?array
    {
        $parts = array_map('trim', explode(';', $header));
        $first = array_shift($parts);
        if (null === $first || !str_contains($first, '=')) {
            return null;
        }
        [$name, $value] = explode('=', $first, 2);
        $name = trim($name);
        if ('' === $name) {
            return null;
        }

        $domain = $host;
        $hostOnly = true;
        $path = '/';
        $expiresAt = null;
        $secure = false;
        $maxAge = null;
        $expires = null;

        foreach ($parts as $attr) {
            $eq = strpos($attr, '=');
            $key = strtolower($eq === false ? $attr : substr($attr, 0, $eq));
            $val = $eq === false ? '' : substr($attr, $eq + 1);
            match ($key) {
                'domain' => [$domain, $hostOnly] = ['' !== ltrim($val, '.') ? ltrim($val, '.') : $host, false],
                'path' => $path = '' !== $val ? $val : '/',
                'secure' => $secure = true,
                'max-age' => $maxAge = (int) $val,
                'expires' => $expires = $val,
                default => null,
            };
        }

        if (null !== $maxAge) {
            $expiresAt = (new \DateTimeImmutable())->modify(sprintf('%+d seconds', $maxAge));
        } elseif (null !== $expires) {
            $ts = strtotime($expires);
            if (false !== $ts) {
                $expiresAt = (new \DateTimeImmutable())->setTimestamp($ts);
            }
        }

        return [
            'name' => $name,
            'value' => trim($value),
            'domain' => $domain,
            'path' => $path,
            'expiresAt' => $expiresAt,
            'secure' => $secure,
            'hostOnly' => $hostOnly,
        ];
    }
}

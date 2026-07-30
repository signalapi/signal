<?php

namespace App\Service;

use App\Entity\ApiRequest;
use App\Entity\User;
use App\Entity\Workspace;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Executes a single ApiRequest, interpolating {{variables}} from the given map.
 * When a workspace is given, cookies are applied/captured via the workspace jar.
 */
class RequestRunner
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly VariableResolver $resolver,
        private readonly CookieJar $cookieJar,
        private readonly bool $insecureSsl = false,
    ) {
    }

    /**
     * $cookieUser selects the personal jar within the workspace; null is the
     * shared jar that automated (user-less) flow runs use.
     *
     * @param array<string, string> $vars
     */
    public function send(ApiRequest $request, array $vars = [], ?Workspace $cookieScope = null, ?User $cookieUser = null): RequestResult
    {
        $method = strtoupper($request->getMethod());
        $resolvedUrl = $this->resolver->resolve($request->getUrl(), $vars) ?? '';

        // Split the URL into base + query, and merge that query with the params
        // table (table wins on conflicts) so neither is sent twice.
        $base = $resolvedUrl;
        $query = [];
        $qpos = strpos($resolvedUrl, '?');
        if (false !== $qpos) {
            $base = substr($resolvedUrl, 0, $qpos);
            foreach (explode('&', substr($resolvedUrl, $qpos + 1)) as $kv) {
                if ('' === $kv) {
                    continue;
                }
                $eq = strpos($kv, '=');
                $k = false === $eq ? $kv : substr($kv, 0, $eq);
                $v = false === $eq ? '' : substr($kv, $eq + 1);
                $query[rawurldecode($k)] = rawurldecode($v);
            }
        }

        $headers = [];
        foreach ($this->resolver->resolvePairs($request->getHeaders(), $vars) as $pair) {
            $headers[$pair['name']] = $pair['value'];
        }

        foreach ($this->resolver->resolvePairs($request->getQueryParams(), $vars) as $pair) {
            $query[$pair['name']] = $pair['value'];
        }

        $this->applyAuth($request->getAuth(), $vars, $headers, $query);

        // Bake the merged query into the URL so it is sent exactly once.
        $url = $base . ($query ? '?' . http_build_query($query) : '');

        // Apply jar cookies unless the request already sets a Cookie header explicitly.
        if (null !== $cookieScope) {
            $hasCookie = false;
            foreach (array_keys($headers) as $hk) {
                if ('cookie' === strtolower((string) $hk)) {
                    $hasCookie = true;
                    break;
                }
            }
            if (!$hasCookie && null !== ($jar = $this->cookieJar->cookieHeader($url, $cookieScope, $cookieUser))) {
                $headers['Cookie'] = $jar;
            }
        }

        $options = ['headers' => $headers, 'max_redirects' => 5];

        // Local/dev: allow self-signed certs when targeting local HTTPS APIs.
        if ($this->insecureSsl) {
            $options['verify_peer'] = false;
            $options['verify_host'] = false;
        }

        if ('none' !== $request->getBodyMode() && null !== $request->getBody()) {
            $body = $this->resolver->resolve($request->getBody(), $vars) ?? '';

            if ('json' === $request->getBodyMode()) {
                $options['body'] = $body;
                $headers['Content-Type'] ??= 'application/json';
                $options['headers'] = $headers;
            } elseif ('form' === $request->getBodyMode()) {
                parse_str($body, $form);
                $options['body'] = $form;
            } else {
                $options['body'] = $body;
            }
        }

        $start = microtime(true);

        try {
            $response = $this->httpClient->request($method, $url, $options);
            $statusCode = $response->getStatusCode();
            $responseHeaders = $response->getHeaders(false);
            $content = $response->getContent(false);
            $durationMs = (microtime(true) - $start) * 1000;

            if (null !== $cookieScope) {
                $this->cookieJar->storeFromResponse($responseHeaders['set-cookie'] ?? [], $url, $cookieScope, $cookieUser);
            }

            return new RequestResult(
                ok: true,
                method: $method,
                url: $url,
                statusCode: $statusCode,
                headers: $responseHeaders,
                body: $content,
                durationMs: $durationMs,
            );
        } catch (ExceptionInterface $e) {
            $durationMs = (microtime(true) - $start) * 1000;

            return new RequestResult(
                ok: false,
                method: $method,
                url: $url,
                durationMs: $durationMs,
                error: $e->getMessage(),
            );
        }
    }

    /**
     * Injects authentication into the outgoing headers/query based on the auth config.
     *
     * @param array<string, string>  $auth
     * @param array<string, string>  $vars
     * @param array<string, string>  $headers
     * @param array<string, string>  $query
     */
    private function applyAuth(array $auth, array $vars, array &$headers, array &$query): void
    {
        $type = $auth['type'] ?? 'none';
        $r = fn (string $key): string => $this->resolver->resolve((string) ($auth[$key] ?? ''), $vars) ?? '';

        switch ($type) {
            case 'bearer':
                $token = $r('token');
                if ('' !== $token) {
                    $headers['Authorization'] = 'Bearer ' . $token;
                }
                break;

            case 'basic':
                $headers['Authorization'] = 'Basic ' . base64_encode($r('username') . ':' . $r('password'));
                break;

            case 'apikey':
                $key = $r('key');
                if ('' !== $key) {
                    if ('query' === ($auth['addTo'] ?? 'header')) {
                        $query[$key] = $r('value');
                    } else {
                        $headers[$key] = $r('value');
                    }
                }
                break;
        }
    }
}

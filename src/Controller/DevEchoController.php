<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Simple echo endpoint that reflects the request as JSON. Handy as a built-in
 * target for trying out collections and test flows without an external API.
 */
class DevEchoController extends AbstractController
{
    #[Route('/_echo', name: 'dev_echo', methods: ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'])]
    public function echo(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent() ?: 'null', true);

        return new JsonResponse([
            'ok' => true,
            'method' => $request->getMethod(),
            'args' => $request->query->all(),
            'json' => $payload,
            'headers' => [
                'content-type' => $request->headers->get('Content-Type'),
                'accept' => $request->headers->get('Accept'),
                'authorization' => $request->headers->get('Authorization'),
                'x-api-key' => $request->headers->get('X-Api-Key'),
                'cookie' => $request->headers->get('Cookie'),
            ],
        ]);
    }

    #[Route('/_setcookie', name: 'dev_setcookie', methods: ['GET'])]
    public function setCookie(Request $request): JsonResponse
    {
        $name = (string) ($request->query->get('name') ?: 'sid');
        $value = (string) ($request->query->get('value') ?: 'abc123');

        $response = new JsonResponse(['ok' => true, 'set' => $name]);
        $response->headers->setCookie(
            \Symfony\Component\HttpFoundation\Cookie::create($name, $value, 0, '/', null, false, true, true)
        );

        return $response;
    }
}

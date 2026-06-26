<?php

namespace App\Controller\Api;

use App\Entity\ApiToken;
use App\Entity\Workspace;
use App\Security\ApiTokenAuthenticator;
use App\Service\Mcp\McpToolRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Model Context Protocol server over Streamable HTTP (JSON-RPC 2.0).
 * Authenticated by the same Bearer API token as /api, so every tool call is
 * scoped to that token's workspace. Lets Claude build and run test flows from
 * natural language.
 */
class McpController extends AbstractController
{
    private const PROTOCOL_VERSION = '2024-11-05';

    public function __construct(private readonly McpToolRegistry $tools)
    {
    }

    #[Route('/mcp', name: 'mcp_endpoint', methods: ['POST'])]
    public function endpoint(Request $request): Response
    {
        $token = $request->attributes->get(ApiTokenAuthenticator::REQUEST_ATTR);
        if (!$token instanceof ApiToken) {
            return new JsonResponse(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32000, 'message' => 'Yetkisiz.']], 401);
        }
        $workspace = $token->getWorkspace();

        $payload = json_decode((string) $request->getContent(), true);
        if (!\is_array($payload)) {
            return $this->rpcError(null, -32700, 'Parse error');
        }

        // Batch support.
        if (array_is_list($payload)) {
            $responses = [];
            foreach ($payload as $one) {
                $r = $this->handle($one, $workspace);
                if (null !== $r) {
                    $responses[] = $r;
                }
            }

            return [] === $responses ? new Response('', 202) : new JsonResponse($responses);
        }

        $response = $this->handle($payload, $workspace);

        return null === $response ? new Response('', 202) : new JsonResponse($response);
    }

    /**
     * @param array<string, mixed> $req
     *
     * @return array<string, mixed>|null Null for notifications (no response).
     */
    private function handle(mixed $req, Workspace $workspace): ?array
    {
        if (!\is_array($req) || ($req['jsonrpc'] ?? null) !== '2.0') {
            return ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32600, 'message' => 'Invalid Request']];
        }

        $id = $req['id'] ?? null;
        $method = (string) ($req['method'] ?? '');
        $params = (array) ($req['params'] ?? []);
        $isNotification = !\array_key_exists('id', $req);

        switch ($method) {
            case 'initialize':
                $merchant = $workspace->getMerchant();

                return $this->result($id, [
                    'protocolVersion' => self::PROTOCOL_VERSION,
                    'capabilities' => ['tools' => new \stdClass()],
                    'serverInfo' => ['name' => 'signal-mcp', 'version' => '1.1.0'],
                    'instructions' => sprintf(
                        'API test platformu. Bu oturum yalnızca "%s" merchant\'ının "%s" workspace\'i ile sınırlıdır — başka merchant/workspace verisine erişemezsiniz. '
                        . 'Collection/istek/environment/DB bağlantılarını listeleyip test akışları kurabilir, collection\'dan flow üretebilir ve senkron/asenkron çalıştırabilirsiniz. '
                        . 'Başlangıçta whoami çağırarak kapsamı doğrulayın.',
                        $merchant->getName(),
                        $workspace->getName(),
                    ),
                ]);

            case 'notifications/initialized':
            case 'notifications/cancelled':
                return null; // notifications: no response

            case 'ping':
                return $this->result($id, new \stdClass());

            case 'tools/list':
                return $this->result($id, ['tools' => $this->tools->definitions()]);

            case 'tools/call':
                return $this->callTool($id, $params, $workspace);

            default:
                if ($isNotification) {
                    return null;
                }

                return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32601, 'message' => "Method not found: $method"]];
        }
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function callTool(mixed $id, array $params, Workspace $workspace): array
    {
        $name = (string) ($params['name'] ?? '');
        $args = (array) ($params['arguments'] ?? []);

        try {
            $data = $this->tools->call($name, $args, $workspace);

            return $this->result($id, [
                'content' => [['type' => 'text', 'text' => (string) json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)]],
                'structuredContent' => $data,
                'isError' => false,
            ]);
        } catch (\Throwable $e) {
            // Tool errors are reported inside the result (MCP convention), not as JSON-RPC errors.
            return $this->result($id, [
                'content' => [['type' => 'text', 'text' => 'Hata: ' . $e->getMessage()]],
                'isError' => true,
            ]);
        }
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    private function result(mixed $id, array|\stdClass $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    private function rpcError(mixed $id, int $code, string $message): JsonResponse
    {
        return new JsonResponse(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]]);
    }
}

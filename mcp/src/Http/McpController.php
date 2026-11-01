<?php

declare(strict_types=1);

namespace Cms\Mcp\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Cms\Abilities\AbilityContext;
use Cms\Mcp\Server;
use Cms\Users\ApiToken;

/** Streamable HTTP endpoint: POST JSON-RPC, JSON responses, stateless. */
final class McpController
{
    public function __construct(private readonly Server $server) {}

    public function handle(Request $request): JsonResponse|Response
    {
        if (! $this->originAllowed($request)) {
            return response()->json(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32600, 'message' => 'Origin not allowed.']], 403);
        }

        $token = ($plain = $request->bearerToken()) ? ApiToken::findByPlain($plain) : null;

        if (! $token || ! $token->user || ! $token->user->hasPermission('admin.access')) {
            return response()->json(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32001, 'message' => 'Authentication required.']], 401, [
                'WWW-Authenticate' => 'Bearer resource_metadata="'.url('/.well-known/oauth-protected-resource').'"'.($plain ? ', error="invalid_token"' : ''),
            ]);
        }

        $token->touchUsage();
        $context = new AbilityContext($token->user, 'mcp', scopes: $token->scopes);
        $payload = json_decode($request->getContent(), true);
        $version = $request->headers->get('MCP-Protocol-Version');

        if (! is_array($payload) || $payload === []) {
            return response()->json(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error.']], 400);
        }

        $messages = array_is_list($payload) ? $payload : [$payload];
        $responses = [];

        foreach (array_slice($messages, 0, 50) as $message) {
            if (is_array($message) && ($out = $this->server->handle($message, $context, $version)) !== null) {
                $responses[] = $out;
            }
        }

        if ($responses === []) {
            return response('', 202);
        }

        return response()->json(array_is_list($payload) ? $responses : $responses[0], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function notAllowed(): Response
    {
        return response('', 405, ['Allow' => 'POST']);
    }

    /** DNS-rebinding protection: browsers send Origin; it must be this site. */
    private function originAllowed(Request $request): bool
    {
        $origin = $request->headers->get('Origin');

        return $origin === null || parse_url($origin, PHP_URL_HOST) === $request->getHost();
    }
}

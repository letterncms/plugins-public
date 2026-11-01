<?php

declare(strict_types=1);

namespace Cms\Mcp\OAuth;

use Cms\Support\Brand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Cms\Http\Csp;
use Cms\Support\SafeFetcher;
use Cms\Users\ApiToken;
use Cms\Users\User;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Minimal OAuth 2.1 authorization server for MCP clients (claude.ai connectors,
 * Claude Code, IDEs): authorization code + PKCE (S256), dynamic client
 * registration and client ID metadata documents, refresh token rotation.
 * Access tokens are ordinary API tokens, visible and revocable under Account.
 */
final class OAuthController
{
    private const int ACCESS_DAYS = 30;

    private const int REFRESH_DAYS = 180;

    public function protectedResource(): JsonResponse
    {
        return response()->json([
            'resource' => url('/mcp'),
            'authorization_servers' => [url('/')],
            'scopes_supported' => ['cms'],
            'bearer_methods_supported' => ['header'],
            'resource_name' => config('app.name').' ('.Brand::get().')',
        ]);
    }

    public function metadata(): JsonResponse
    {
        return response()->json([
            'issuer' => url('/'),
            'authorization_endpoint' => url('/oauth/authorize'),
            'token_endpoint' => url('/oauth/token'),
            'registration_endpoint' => url('/oauth/register'),
            'revocation_endpoint' => url('/oauth/revoke'),
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'scopes_supported' => ['cms'],
            'client_id_metadata_document_supported' => true,
            'authorization_response_iss_parameter_supported' => true,
        ]);
    }

    /** RFC 7591 dynamic registration (public clients only). */
    public function register(Request $request): JsonResponse
    {
        $uris = array_values(array_filter((array) $request->json('redirect_uris'), fn ($u) => is_string($u) && $this->validRedirect($u)));

        if ($uris === []) {
            return response()->json(['error' => 'invalid_redirect_uri', 'error_description' => 'Provide https:// or loopback redirect_uris.'], 400);
        }

        $id = 'cms_'.Str::lower(Str::random(24));
        $name = mb_substr(strip_tags((string) ($request->json('client_name') ?: 'MCP client')), 0, 120);

        DB::table('oauth_clients')->insert(['id' => $id, 'name' => $name, 'redirect_uris' => json_encode($uris), 'created_at' => now(), 'updated_at' => now()]);

        return response()->json([
            'client_id' => $id,
            'client_name' => $name,
            'redirect_uris' => $uris,
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
        ], 201);
    }

    public function authorize(Request $request): Response
    {
        try {
            $params = $this->validateAuthorize($request->query());
        } catch (OAuthError $e) {
            return $this->errorPage($e->getMessage());
        }

        $user = $request->user();

        if (! $user) {
            return redirect('/admin?'.http_build_query(['next' => $request->getRequestUri()]));
        }

        if (! $user->hasPermission('admin.access')) {
            return $this->errorPage(__('Your account cannot use the admin, so it cannot connect apps.'));
        }

        $nonce = Csp::nonce();

        $app = $params['client']['name'];
        $site = (string) config('app.name');

        return response()->view('mcp::consent', [
            'params' => $params,
            'nonce' => $nonce,
            'locale' => app()->make('translator')->getLocale(),
            // In the person's admin language.
            'text' => [
                'title' => __('Connect :app', ['app' => $app]).' · '.$site,
                'heading' => __('Connect :app to :site?', ['app' => $app, 'site' => $site]),
                'as' => __('It will act as :name, with your permissions.', ['name' => $user->name]),
                'can' => [__('Read your content, design system, blocks and settings'), __('Create and edit what you are allowed to change'), __('Every change is recorded in the audit log')],
                'full' => __('Full access'),
                'fullHint' => __('Everything you can do in the admin'),
                'read' => __('Read Only'),
                'readHint' => __('Look, never change anything'),
                'cancel' => __('Cancel'),
                'connect' => __('Connect'),
                'returns' => __('Returns to :host. Revoke access any time under Account → API tokens.', ['host' => parse_url($params['redirect_uri'], PHP_URL_HOST)]),
            ],
        ])->header('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; form-action 'self' ".$this->formAction($params['redirect_uri'])."; frame-ancestors 'none'");
    }

    public function approve(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $params = $this->validateAuthorize($request->only(['response_type', 'client_id', 'redirect_uri', 'code_challenge', 'code_challenge_method', 'state', 'scope', 'resource']));
        } catch (OAuthError $e) {
            return $this->errorPage($e->getMessage());
        }

        $query = ['state' => $params['state'], 'iss' => url('/')];

        if ($request->input('decision') !== 'approve') {
            return redirect()->away($this->append($params['redirect_uri'], [...$query, 'error' => 'access_denied']));
        }

        $code = Str::random(48);
        Cache::put('oauth:code:'.hash('sha256', $code), [
            'client_id' => $params['client_id'],
            'client_name' => $params['client']['name'],
            'redirect_uri' => $params['redirect_uri'],
            'challenge' => $params['code_challenge'],
            'user_id' => $user->id,
            'scopes' => $request->input('access') === 'read' ? ['read'] : ['*'],
        ], now()->addMinutes(5));

        cms()->hooks()->dispatch('oauth.authorized', $user, $params['client']);

        return redirect()->away($this->append($params['redirect_uri'], [...$query, 'code' => $code]));
    }

    public function token(Request $request): JsonResponse
    {
        return match ($request->input('grant_type')) {
            'authorization_code' => $this->exchangeCode($request),
            'refresh_token' => $this->refresh($request),
            default => $this->tokenError('unsupported_grant_type'),
        };
    }

    public function revoke(Request $request): JsonResponse
    {
        $plain = (string) $request->input('token');

        if ($token = ApiToken::findByPlain($plain)) {
            DB::table('oauth_refresh_tokens')->where('api_token_id', $token->id)->delete();
            $token->delete();
        } else {
            $row = DB::table('oauth_refresh_tokens')->where('token_hash', hash('sha256', $plain))->first();

            if ($row) {
                ApiToken::query()->whereKey($row->api_token_id)->delete();
                DB::table('oauth_refresh_tokens')->where('token_hash', $row->token_hash)->delete();
            }
        }

        return response()->json([]);
    }

    private function exchangeCode(Request $request): JsonResponse
    {
        $code = (string) $request->input('code');
        $data = Cache::pull('oauth:code:'.hash('sha256', $code));

        if (! is_array($data) || $data['client_id'] !== $request->input('client_id') || $data['redirect_uri'] !== $request->input('redirect_uri')) {
            return $this->tokenError('invalid_grant');
        }

        $verifier = (string) $request->input('code_verifier');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        if (strlen($verifier) < 43 || ! hash_equals($data['challenge'], $challenge)) {
            return $this->tokenError('invalid_grant', 'PKCE verification failed.');
        }

        $user = User::query()->find($data['user_id']);

        return $user ? $this->issue($user, $data['client_id'], $data['client_name'], $data['scopes']) : $this->tokenError('invalid_grant');
    }

    private function refresh(Request $request): JsonResponse
    {
        $row = DB::table('oauth_refresh_tokens')->where('token_hash', hash('sha256', (string) $request->input('refresh_token')))->first();

        if (! $row || strtotime((string) $row->expires_at) < time() || $row->client_id !== $request->input('client_id')) {
            return $this->tokenError('invalid_grant');
        }

        $old = ApiToken::query()->find($row->api_token_id);
        $user = User::query()->find($row->user_id);
        DB::table('oauth_refresh_tokens')->where('token_hash', $row->token_hash)->delete();
        $name = $old?->name ?? 'MCP client';
        $old?->delete();

        return $user ? $this->issue($user, $row->client_id, preg_replace('/^MCP: /', '', $name), (array) json_decode((string) $row->scopes, true)) : $this->tokenError('invalid_grant');
    }

    private function issue(User $user, string $clientId, string $clientName, array $scopes): JsonResponse
    {
        [$token, $plain] = ApiToken::issue($user, 'MCP: '.$clientName, $scopes ?: ['*'], now()->addDays(self::ACCESS_DAYS));
        $refresh = 'cmsr_'.Str::random(48);

        DB::table('oauth_refresh_tokens')->insert([
            'token_hash' => hash('sha256', $refresh),
            'api_token_id' => $token->id,
            'client_id' => $clientId,
            'user_id' => $user->id,
            'scopes' => json_encode($scopes),
            'expires_at' => now()->addDays(self::REFRESH_DAYS),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'access_token' => $plain,
            'token_type' => 'Bearer',
            'expires_in' => self::ACCESS_DAYS * 86400,
            'refresh_token' => $refresh,
            'scope' => 'cms',
        ], 200, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
    }

    /** @return array{client: array{name: string, redirect_uris: list<string>}, client_id: string, redirect_uri: string, code_challenge: string, state: string, response_type: string, code_challenge_method: string, scope: string, resource: string} */
    private function validateAuthorize(array $q): array
    {
        $clientId = (string) ($q['client_id'] ?? '');
        $client = $this->client($clientId) ?? throw new OAuthError(__('This app is not registered.'));
        $redirect = (string) ($q['redirect_uri'] ?? '');

        if (! in_array($redirect, $client['redirect_uris'], true)) {
            throw new OAuthError(__('The redirect address does not match the app’s registration.'));
        }

        if (($q['response_type'] ?? '') !== 'code' || ($q['code_challenge_method'] ?? '') !== 'S256' || strlen((string) ($q['code_challenge'] ?? '')) < 43) {
            throw new OAuthError(__('The app must use the authorization code flow with PKCE (S256).'));
        }

        return [
            'client' => $client,
            'client_id' => $clientId,
            'redirect_uri' => $redirect,
            'code_challenge' => (string) $q['code_challenge'],
            'code_challenge_method' => 'S256',
            'response_type' => 'code',
            'state' => (string) ($q['state'] ?? ''),
            'scope' => (string) ($q['scope'] ?? 'cms'),
            'resource' => (string) ($q['resource'] ?? url('/mcp')),
        ];
    }

    /** @return array{name: string, redirect_uris: list<string>}|null */
    private function client(string $clientId): ?array
    {
        if ($clientId === '') {
            return null;
        }

        // Client ID metadata document: the client_id is an https URL describing the client.
        if (str_starts_with($clientId, 'https://')) {
            return Cache::remember('oauth:cimd:'.hash('sha256', $clientId), 3600, function () use ($clientId) {
                try {
                    $doc = json_decode(SafeFetcher::get($clientId, 64_000, 5)['body'], true);
                } catch (Throwable) {
                    return null;
                }

                if (! is_array($doc) || ($doc['client_id'] ?? null) !== $clientId) {
                    return null;
                }

                $uris = array_values(array_filter((array) ($doc['redirect_uris'] ?? []), fn ($u) => is_string($u) && $this->validRedirect($u)));

                return $uris ? ['name' => mb_substr(strip_tags((string) ($doc['client_name'] ?? parse_url($clientId, PHP_URL_HOST))), 0, 120), 'redirect_uris' => $uris] : null;
            });
        }

        $row = DB::table('oauth_clients')->where('id', $clientId)->first();

        return $row ? ['name' => $row->name, 'redirect_uris' => (array) json_decode($row->redirect_uris, true)] : null;
    }

    private function validRedirect(string $uri): bool
    {
        $parts = parse_url($uri);

        if (! $parts || isset($parts['fragment']) || ! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        return $parts['scheme'] === 'https' || ($parts['scheme'] === 'http' && in_array($parts['host'], ['127.0.0.1', 'localhost', '[::1]'], true));
    }

    private function append(string $url, array $query): string
    {
        return $url.(str_contains($url, '?') ? '&' : '?').http_build_query(array_filter($query, static fn ($v) => $v !== ''));
    }

    private function formAction(string $redirect): string
    {
        $p = parse_url($redirect);

        return ($p['scheme'] ?? 'https').'://'.($p['host'] ?? '').(isset($p['port']) ? ':'.$p['port'] : '');
    }

    private function tokenError(string $error, string $description = ''): JsonResponse
    {
        return response()->json(array_filter(['error' => $error, 'error_description' => $description]), 400, ['Cache-Control' => 'no-store']);
    }

    private function errorPage(string $message): Response
    {
        return response('<!doctype html><meta charset="utf-8"><title>'.e(__('This app cannot connect')).'</title><body style="font:15px system-ui;max-width:32rem;margin:15vh auto;padding:0 1.25rem"><h1 style="font-size:20px">'.e(__('This app cannot connect')).'</h1><p>'.e($message).'</p></body>', 400, ['Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'"]);
    }
}

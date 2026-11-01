<?php

declare(strict_types=1);

namespace Cms\Mcp\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Cms\Tests\TestCase;
use Cms\Users\ApiToken;

final class McpTest extends TestCase
{
    protected function plugins(): array
    {
        return ['letterncms/mcp'];
    }

    private function rpc(string $token, string $method, array $params = [], bool $modern = true): \Illuminate\Testing\TestResponse
    {
        if ($modern) {
            $params['_meta']['io.modelcontextprotocol/protocolVersion'] = '2026-07-28';
        }

        return $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params ?: (object) []], ['Authorization' => "Bearer {$token}"]);
    }

    public function test_discover_and_tools_reflect_permissions(): void
    {
        [, $admin] = ApiToken::issue($this->admin(), 'a');
        [, $author] = ApiToken::issue($this->userWithRole('author'), 'b');

        $this->rpc($admin, 'server/discover')->assertOk()->assertJsonPath('result.supportedVersions.0', '2026-07-28')->assertJsonPath('result.resultType', 'complete');

        $adminTools = array_column($this->rpc($admin, 'tools/list')->json('result.tools'), 'name');
        $authorTools = array_column($this->rpc($author, 'tools/list')->json('result.tools'), 'name');

        $this->assertContains('entries.create', $adminTools);
        $this->assertContains('collections.save', $adminTools);
        $this->assertContains('entries.create', $authorTools);
        $this->assertNotContains('collections.save', $authorTools);
        $this->assertNotContains('tokens.create', $adminTools, 'token minting is never exposed to agents');
    }

    public function test_tools_call_runs_abilities_and_reports_errors_as_results(): void
    {
        [, $token] = ApiToken::issue($this->admin(), 'a');

        $ok = $this->rpc($token, 'tools/call', ['name' => 'entries.create', 'arguments' => ['collection' => 'pages', 'title' => 'From an agent']]);
        $ok->assertOk()->assertJsonPath('result.isError', false)->assertJsonPath('result.structuredContent.slug', 'from-an-agent');

        $this->rpc($token, 'tools/call', ['name' => 'entries.create', 'arguments' => ['collection' => 'pages']])->assertJsonPath('result.isError', true);

        $this->assertDatabaseHas('audit_log', ['ability' => 'entries/create', 'transport' => 'mcp']);
    }

    public function test_read_only_tokens_cannot_write(): void
    {
        [, $token] = ApiToken::issue($this->admin(), 'ro', ['read']);

        $this->assertNotContains('entries.create', array_column($this->rpc($token, 'tools/list')->json('result.tools'), 'name'));
        $this->rpc($token, 'tools/call', ['name' => 'entries.create', 'arguments' => ['collection' => 'pages', 'title' => 'x']])->assertJsonPath('result.isError', true);
    }

    public function test_protocol_errors(): void
    {
        [, $token] = ApiToken::issue($this->admin(), 'a');

        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertUnauthorized()->assertHeader('WWW-Authenticate');
        $this->rpc($token, 'tools/list', ['_meta' => ['io.modelcontextprotocol/protocolVersion' => '1999-01-01']], modern: false)->assertJsonPath('error.code', -32022);
        $this->rpc($token, 'nope/method')->assertJsonPath('error.code', -32601);
        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], ['Authorization' => "Bearer {$token}", 'Origin' => 'https://evil.example'])->assertForbidden();
    }

    public function test_legacy_initialize_and_resources(): void
    {
        [, $token] = ApiToken::issue($this->admin(), 'a');

        $this->rpc($token, 'initialize', ['protocolVersion' => '2025-11-25', 'capabilities' => [], 'clientInfo' => ['name' => 't', 'version' => '1']], modern: false)
            ->assertJsonPath('result.protocolVersion', '2025-11-25')
            ->assertJsonPath('result.serverInfo.name', 'cms');

        $uris = array_column($this->rpc($token, 'resources/list')->json('result.resources'), 'uri');
        $this->assertContains('cms://collections', $uris);
        $this->rpc($token, 'resources/read', ['uri' => 'cms://collections'])->assertJsonPath('result.contents.0.mimeType', 'application/json');
        $this->rpc($token, 'prompts/get', ['name' => 'build-page', 'arguments' => ['brief' => 'A pricing page']])->assertJsonPath('result.messages.0.role', 'user');
    }

    public function test_oauth_authorization_code_flow_with_pkce(): void
    {
        $client = $this->postJson('/oauth/register', ['client_name' => 'Test agent', 'redirect_uris' => ['http://127.0.0.1:7777/callback']])->assertCreated()->json();
        $verifier = str_repeat('v', 50);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $query = ['response_type' => 'code', 'client_id' => $client['client_id'], 'redirect_uri' => 'http://127.0.0.1:7777/callback', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256', 'state' => 'xyz'];

        $this->get('/oauth/authorize?'.http_build_query($query))->assertRedirect();

        $admin = $this->admin();
        $this->actingAs($admin)->get('/oauth/authorize?'.http_build_query($query))->assertOk()->assertSee('Connect Test agent');

        $redirect = $this->actingAs($admin)->post('/oauth/authorize', [...$query, 'decision' => 'approve', 'access' => 'full'])->assertRedirect()->headers->get('Location');
        parse_str((string) parse_url($redirect, PHP_URL_QUERY), $params);
        $this->assertSame('xyz', $params['state']);

        $this->postJson('/oauth/token', ['grant_type' => 'authorization_code', 'code' => $params['code'], 'client_id' => $client['client_id'], 'redirect_uri' => 'http://127.0.0.1:7777/callback', 'code_verifier' => 'wrong'.str_repeat('x', 45)])->assertStatus(400);

        // Codes are single-use, so get a fresh one for the real exchange.
        $redirect = $this->actingAs($admin)->post('/oauth/authorize', [...$query, 'decision' => 'approve'])->headers->get('Location');
        parse_str((string) parse_url($redirect, PHP_URL_QUERY), $params);
        $tokens = $this->postJson('/oauth/token', ['grant_type' => 'authorization_code', 'code' => $params['code'], 'client_id' => $client['client_id'], 'redirect_uri' => 'http://127.0.0.1:7777/callback', 'code_verifier' => $verifier])->assertOk()->json();

        $this->rpc($tokens['access_token'], 'tools/list')->assertOk();

        $refreshed = $this->postJson('/oauth/token', ['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'], 'client_id' => $client['client_id']])->assertOk()->json();
        $this->assertNotSame($tokens['access_token'], $refreshed['access_token']);
        $this->rpc($tokens['access_token'], 'tools/list')->assertUnauthorized();
        $this->rpc($refreshed['access_token'], 'tools/list')->assertOk();
    }

    public function test_agents_lists_oauth_clients_and_their_changes(): void
    {
        $admin = $this->admin();
        ApiToken::issue($admin, 'MCP: Claude', ['*'], now()->addDay());
        ApiToken::issue($admin, 'MCP: Claude', ['*'], now()->addDay());
        ApiToken::issue($admin, 'MCP: Old', ['*'], now()->subDay());
        ApiToken::issue($admin, 'Deploy script');
        DB::table('audit_log')->insert(['id' => strtolower((string) Str::ulid()), 'user_id' => $admin->id, 'ability' => 'entries/create', 'transport' => 'mcp', 'input' => json_encode(['title' => 'Hello']), 'created_at' => now()]);
        DB::table('audit_log')->insert(['id' => strtolower((string) Str::ulid()), 'user_id' => $admin->id, 'ability' => 'entries/create', 'transport' => 'admin', 'input' => null, 'created_at' => now()]);

        $data = $this->actingAs($admin)->postJson('/api/admin/abilities/mcp/agents', [])->assertOk()->json('data');

        $this->assertSame(1, $data['connected']);
        $this->assertSame('Claude', $data['agents'][0]['name']);
        $this->assertCount(1, $data['activity']);
        $this->assertSame('Hello', $data['activity'][0]['subject']);
    }
}

<?php

use App\Mcp\Servers\ForgeServer;
use App\Mcp\Tools\ListOrganizations;
use App\Models\AuditEntry;
use App\Permissions\Capability;
use App\Permissions\ScopeType;
use App\Permissions\Target;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Tests\Fixtures\Mcp\DeployProbe;
use Tests\Fixtures\Mcp\ProbeServer;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\call;

function mcpRegisterProbeRoute(): void
{
    Mcp::web('/mcp-probe', ProbeServer::class)->middleware(['auth:sanctum', 'abilities:mcp', 'throttle:mcp']);
}

it('unauthenticated request gets 401', function () {
    mcpPost('tools/list')
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate');

    // An MCP client that only accepts an event stream must not be redirected to a login page.
    $response = call('POST', '/mcp', [], [], [], ['HTTP_ACCEPT' => 'text/event-stream', 'CONTENT_TYPE' => 'application/json'],
        json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']));

    expect($response->isRedirect())->toBeFalse()
        ->and($response->getStatusCode())->toBe(401);
});

it('session-authenticated user without bearer gets 401', function () {
    actingAs(mcpOwner());

    mcpPost('tools/list')->assertUnauthorized();
});

it('token without mcp ability is rejected', function () {
    $user = mcpOwner();
    $plain = $user->createToken('Other app', ['other'])->plainTextToken;

    mcpPost('tools/list', bearer: $plain)->assertForbidden();
});

it('revoked token gets 401', function () {
    $new = mcpNewToken(mcpOwner());
    $new->accessToken->delete();

    mcpPost('tools/list', bearer: $new->plainTextToken)->assertUnauthorized();
});

it('expired token gets 401', function () {
    $new = mcpNewToken(mcpOwner());
    $new->accessToken->forceFill(['expires_at' => now()->subMinute()])->save();

    mcpPost('tools/list', bearer: $new->plainTextToken)->assertUnauthorized();
});

it('fresh token sees only read tools in tools/list', function () {
    $new = mcpNewToken(mcpOwner());

    $response = mcpPost('tools/list', bearer: $new->plainTextToken)->assertOk();

    expect(mcpToolNames($response))->toBe(['list_organizations', 'list_servers', 'get_server', 'list_sites', 'get_site', 'get_deployment_status', 'list_deployments', 'get_deployment_log', 'get_site_log', 'list_workers', 'get_worker_log', 'get_command_result', 'get_deployment_script', 'get_integration_status', 'list_scheduled_jobs', 'get_scheduled_job_output', 'list_server_events', 'get_server_event_output']);
});

it('identifies itself as Forge MCP with discovery instructions', function () {
    $new = mcpNewToken(mcpOwner());

    $response = mcpPost('initialize', [
        'protocolVersion' => '2025-06-18',
        'capabilities' => (object) [],
        'clientInfo' => ['name' => 'test', 'version' => '1.0'],
    ], $new->plainTextToken)->assertOk();

    expect($response->json('result.serverInfo.name'))->toBe('Forge MCP')
        ->and($response->json('result.instructions'))
        ->toContain('list_organizations')
        ->toContain('list_servers')
        ->toContain('list_sites')
        ->toContain('Forge MCP dashboard')
        ->toContain('Do not retry');
});

it('hides tools the token cannot use and refuses to call them over http', function () {
    mcpRegisterProbeRoute();
    $new = mcpNewToken(mcpOwner());
    DeployProbe::$runs = 0;

    $list = mcpPost('tools/list', bearer: $new->plainTextToken, uri: '/mcp-probe')->assertOk();
    expect(mcpToolNames($list))->toContain('list_organizations')->not->toContain('deploy_probe');

    $call = mcpPost('tools/call', ['name' => 'deploy_probe', 'arguments' => ['organization' => 'acme']], $new->plainTextToken, '/mcp-probe');

    expect($call->json('error.message'))->toBe('Tool [deploy_probe] not found.')
        ->and(DeployProbe::$runs)->toBe(0);
});

it('checks permission on every call even when the tool is listed', function () {
    mcpRegisterProbeRoute();
    $user = mcpOwner(['acme', 'beta']);
    $new = mcpNewToken($user, [[Capability::Deploy, ScopeType::Organization, new Target('acme'), true]]);
    DeployProbe::$runs = 0;

    $list = mcpPost('tools/list', bearer: $new->plainTextToken, uri: '/mcp-probe')->assertOk();
    expect(mcpToolNames($list))->toContain('deploy_probe');

    $denied = mcpPost('tools/call', ['name' => 'deploy_probe', 'arguments' => ['organization' => 'beta', 'server_id' => 5]], $new->plainTextToken, '/mcp-probe');

    expect($denied->json('result.isError'))->toBeTrue()
        ->and($denied->json('result.content.0.text'))->toBe('Deploy is not permitted on server 5 (beta) for this access token. The user can enable it in the Forge MCP dashboard.')
        ->and(DeployProbe::$runs)->toBe(0);

    $entry = AuditEntry::query()->sole();
    expect($entry->outcome)->toBe('denied')
        ->and($entry->tool)->toBe('deploy_probe')
        ->and($entry->organization)->toBe('beta')
        ->and($entry->server_id)->toBe(5)
        ->and($entry->personal_access_token_id)->toBe($new->accessToken->id);

    $allowed = mcpPost('tools/call', ['name' => 'deploy_probe', 'arguments' => ['organization' => 'acme', 'server_id' => 5]], $new->plainTextToken, '/mcp-probe');

    expect($allowed->json('result.isError'))->toBeFalse()
        ->and($allowed->json('result.content.0.text'))->toBe('deployed')
        ->and(DeployProbe::$runs)->toBe(1);
});

it('denies a hidden tool that is invoked directly, bypassing registration', function () {
    $user = mcpOwner();
    $token = mcpToken($user);
    DeployProbe::$runs = 0;
    Auth::setUser(mcpActAs($user, $token));

    $tool = new DeployProbe;
    expect(app()->call([$tool, 'shouldRegister']))->toBeFalse();

    $response = app()->call([$tool, 'handle'], ['request' => new Request(['organization' => 'acme'])]);

    expect($response)->toBeInstanceOf(Response::class)
        ->and($response->isError())->toBeTrue()
        ->and((string) $response->content())->toContain('Deploy is not permitted on server 1 (acme)')
        ->and(DeployProbe::$runs)->toBe(0)
        ->and(AuditEntry::query()->sole()->outcome)->toBe('denied');
});

it('rate limits mcp requests to 120 per minute per token', function () {
    $user = mcpOwner();
    $token = mcpToken($user);

    $request = HttpRequest::create('/mcp', 'POST');
    $request->setUserResolver(fn () => mcpActAs($user, $token));

    $limit = RateLimiter::limiter('mcp')($request);

    expect($limit->maxAttempts)->toBe(120)
        ->and($limit->decaySeconds)->toBe(60)
        ->and($limit->key)->toBe((string) $token->id);
});

it('registers list_organizations under its snake_case name', function () {
    expect((new ListOrganizations)->name())->toBe('list_organizations');
});

it('a token with every capability sees all registered tools on one page', function () {
    $user = mcpOwner();
    $grants = array_map(fn (Capability $c) => [$c, ScopeType::Global, new Target, true], Capability::cases());
    $new = mcpNewToken($user, $grants);

    $response = mcpPost('tools/list', bearer: $new->plainTextToken)->assertOk();

    $registered = (new ReflectionProperty(ForgeServer::class, 'tools'))->getDefaultValue();
    expect(mcpToolNames($response))->toHaveCount(count($registered))
        ->and($response->json('result.nextCursor'))->toBeNull();
});

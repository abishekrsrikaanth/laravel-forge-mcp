<?php

declare(strict_types=1);

namespace App\Mcp\Support;

use App\Models\TokenDefault;
use Illuminate\Http\Request as HttpRequest;
use Laravel\Mcp\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Resolves the default organization/server/site for a tool call. The X-Forge-* headers of the
 * underlying HTTP request (set per project in the client's .mcp.json) win as a bundle: when any of them
 * is present the token's stored defaults are ignored entirely. Without an HTTP request (stdio, or the
 * package's test helpers) there are no headers. Defaults only fill in ids; they never bypass any check.
 */
final class Defaults
{
    public const string ORGANIZATION_HEADER = 'X-Forge-Organization';

    public const string SERVER_HEADER = 'X-Forge-Server';

    public const string SITE_HEADER = 'X-Forge-Site';

    private const string SLUG_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/D';

    private const string ID_PATTERN = '/^[1-9][0-9]{0,17}$/D';

    /** @throws ToolError for an invalid header (its value is never echoed) or a site header without a server header */
    public static function resolve(Request $request, PersonalAccessToken $token): ResolvedDefaults
    {
        return self::fromHeaders() ?? self::fromToken($token);
    }

    private static function fromHeaders(): ?ResolvedDefaults
    {
        $http = app()->bound('request') ? app('request') : null;
        if (! $http instanceof HttpRequest) {
            return null;
        }

        $headers = $http->headers;
        if (! $headers->has(self::ORGANIZATION_HEADER) && ! $headers->has(self::SERVER_HEADER) && ! $headers->has(self::SITE_HEADER)) {
            return null;
        }

        $organization = self::header($http, self::ORGANIZATION_HEADER, self::SLUG_PATTERN);
        $serverId = self::header($http, self::SERVER_HEADER, self::ID_PATTERN);
        $siteId = self::header($http, self::SITE_HEADER, self::ID_PATTERN);

        if ($siteId !== null && $serverId === null) {
            throw new ToolError(self::SITE_HEADER.' requires '.self::SERVER_HEADER.'.');
        }

        return new ResolvedDefaults(
            $organization,
            $serverId !== null ? (int) $serverId : null,
            $siteId !== null ? (int) $siteId : null,
            'header',
        );
    }

    /** The header's value when present and valid, null when absent. */
    private static function header(HttpRequest $http, string $name, string $pattern): ?string
    {
        if (! $http->headers->has($name)) {
            return null;
        }

        $value = $http->headers->all(strtolower($name));
        if (count($value) !== 1 || ! is_string($value[0]) || preg_match($pattern, $value[0]) !== 1) {
            throw new ToolError("Invalid {$name} header.");
        }

        return $value[0];
    }

    private static function fromToken(PersonalAccessToken $token): ResolvedDefaults
    {
        $row = TokenDefault::query()->where('personal_access_token_id', $token->getKey())->first();
        if ($row === null) {
            return ResolvedDefaults::none();
        }

        // A site default is meaningless without its server (writes enforce this; ignore it defensively here).
        $serverId = $row->server_id !== null && $row->server_id > 0 ? $row->server_id : null;
        $siteId = $serverId !== null && $row->site_id !== null && $row->site_id > 0 ? $row->site_id : null;

        return new ResolvedDefaults($row->organization, $serverId, $siteId, 'token');
    }
}

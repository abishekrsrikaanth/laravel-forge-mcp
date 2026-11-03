<?php

declare(strict_types=1);

namespace App\Forge;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class ForgeClient
{
    public const BASE_URL = 'https://forge.laravel.com/api';

    private const PAGE_SIZE = 100;

    private const MAX_PAGES = 20;

    private const INDEX_KEY = 'forge:cache-index';

    public function __construct(private readonly string $token) {}

    /** @return array<string, mixed> */
    public function me(): array
    {
        return $this->get('/me', [], 'fetch current user');
    }

    /** @return list<array<string, mixed>> */
    public function organizations(): array
    {
        return $this->cached('organizations', fn () => $this->getAll('/orgs', [], 'list organizations'));
    }

    /** @return list<array<string, mixed>> */
    public function servers(string $org): array
    {
        return $this->cached("servers:{$org}", fn () => $this->getAll("/orgs/{$org}/servers", [], 'list servers'));
    }

    /** @return array<string, mixed> */
    public function server(string $org, int $serverId): array
    {
        return $this->get("/orgs/{$org}/servers/{$serverId}", [], 'fetch server');
    }

    /** @return list<array<string, mixed>> */
    public function sites(string $org, int $serverId): array
    {
        return $this->cached(
            "sites:{$org}:{$serverId}",
            fn () => $this->getAll("/orgs/{$org}/servers/{$serverId}/sites", [], 'list sites'),
        );
    }

    /** @return list<array<string, mixed>> */
    public function organizationSites(string $org): array
    {
        return $this->cached("org-sites:{$org}", fn () => $this->getAll("/orgs/{$org}/sites", [], 'list sites'));
    }

    /** @return array<string, mixed> */
    public function site(string $org, int $siteId): array
    {
        return $this->get("/orgs/{$org}/sites/{$siteId}", [], 'fetch site');
    }

    /** Forget every list response cached by any ForgeClient. */
    public static function flushCache(): void
    {
        $keys = Cache::get(self::INDEX_KEY, []);

        foreach (is_array($keys) ? $keys : [] as $key) {
            Cache::forget($key);
        }

        Cache::forget(self::INDEX_KEY);
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
            ->withToken($this->token)
            ->acceptJson()
            ->timeout(15);
    }

    /**
     * GET a single resource (or an unpaginated document) and flatten it.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function get(string $path, array $query, string $action): array
    {
        $body = $this->dispatch(fn () => $this->request()->get($path, $query), $action)->json();

        return $this->flattenDocument(is_array($body) ? $body : []);
    }

    /**
     * GET every page of a collection (cursor pagination, capped at MAX_PAGES).
     *
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    private function getAll(string $path, array $query, string $action): array
    {
        $records = [];
        $cursor = null;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $params = $query + ['page[size]' => self::PAGE_SIZE];
            if ($cursor !== null) {
                $params['page[cursor]'] = $cursor;
            }

            $body = $this->dispatch(fn () => $this->request()->get($path, $params), $action)->json();
            $body = is_array($body) ? $body : [];

            foreach ($body['data'] ?? [] as $resource) {
                if (is_array($resource)) {
                    $records[] = $this->flatten($resource);
                }
            }

            $cursor = data_get($body, 'meta.next_cursor');
            if (! is_string($cursor) || $cursor === '') {
                break;
            }
        }

        return $records;
    }

    /**
     * Send a write request. Returns the flattened record, or null for empty (e.g. 204) responses.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>|null
     */
    protected function send(string $method, string $path, array $body, string $action): ?array
    {
        $response = $this->dispatch(
            fn () => $this->request()->send(strtoupper($method), $path, $body === [] ? [] : ['json' => $body]),
            $action,
        );

        $json = $response->json();

        return is_array($json) && isset($json['data']) ? $this->flattenDocument($json) : null;
    }

    /** @param  Closure(): Response  $call */
    private function dispatch(Closure $call, string $action): Response
    {
        try {
            $response = $call();
        } catch (ConnectionException) {
            throw ForgeException::unreachable($action);
        }

        if (! $response->successful()) {
            throw ForgeException::fromResponse($response, $action, $this->token);
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    private function flattenDocument(array $document): array
    {
        $data = $document['data'] ?? null;

        return is_array($data) ? $this->flatten($data) : [];
    }

    /**
     * Flatten a JSON:API resource object into ['id' => ..., ...attributes, {relation}_id => ...].
     *
     * @param  array<string, mixed>  $resource
     * @return array<string, mixed>
     */
    private function flatten(array $resource): array
    {
        $record = ['id' => $this->castId($resource['id'] ?? null)];

        foreach ($resource['attributes'] ?? [] as $key => $value) {
            if ($key !== 'id') {
                $record[$key] = $value;
            }
        }

        foreach ($resource['relationships'] ?? [] as $name => $relation) {
            $related = $relation['data'] ?? null;

            // Only to-one relationships carry a single id.
            if (is_array($related) && array_key_exists('id', $related) && ! array_key_exists("{$name}_id", $record)) {
                $record["{$name}_id"] = $this->castId($related['id']);
            }
        }

        return $record;
    }

    private function castId(mixed $id): int|string|null
    {
        if (is_string($id) && ctype_digit($id)) {
            return (int) $id;
        }

        return is_int($id) || is_string($id) ? $id : null;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $resolve
     * @return T
     */
    private function cached(string $suffix, Closure $resolve): mixed
    {
        $key = 'forge:'.sha1($this->token).':'.$suffix;

        $value = Cache::get($key);
        if ($value !== null) {
            return $value;
        }

        $value = $resolve();

        Cache::put($key, $value, (int) config('forge-mcp.list_cache_ttl'));

        $index = Cache::get(self::INDEX_KEY, []);
        $index = is_array($index) ? $index : [];
        $index[$key] = $key;
        Cache::forever(self::INDEX_KEY, $index);

        return $value;
    }
}

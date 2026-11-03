<?php

declare(strict_types=1);

namespace App\Forge;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

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
        return $this->cached("servers:{$org}", fn () => $this->getAll($this->orgPath($org).'/servers', [], 'list servers'));
    }

    /** @return array<string, mixed> */
    public function server(string $org, int $serverId): array
    {
        return $this->get($this->serverPath($org, $serverId), [], 'fetch server');
    }

    /** @return list<array<string, mixed>> */
    public function sites(string $org, int $serverId): array
    {
        return $this->cached(
            "sites:{$org}:{$serverId}",
            fn () => $this->getAll($this->serverPath($org, $serverId).'/sites', [], 'list sites'),
        );
    }

    /** @return list<array<string, mixed>> */
    public function organizationSites(string $org): array
    {
        return $this->cached("org-sites:{$org}", fn () => $this->getAll($this->orgPath($org).'/sites', [], 'list sites'));
    }

    /** @return array<string, mixed> */
    public function site(string $org, int $siteId): array
    {
        return $this->get($this->orgPath($org).'/sites/'.$siteId, [], 'fetch site');
    }

    /** @return array<string, mixed> */
    public function deploy(string $org, int $serverId, int $siteId): array
    {
        return $this->send('POST', $this->sitePath($org, $serverId, $siteId).'/deployments', [], 'start deployment') ?? [];
    }

    /** @return array{status: ?string, started_at: ?string} */
    public function deploymentStatus(string $org, int $serverId, int $siteId): array
    {
        $status = $this->get($this->sitePath($org, $serverId, $siteId).'/deployments/status', [], 'fetch deployment status');

        return ['status' => $status['status'] ?? null, 'started_at' => $status['started_at'] ?? null];
    }

    /** @return array<string, mixed> */
    public function deployment(string $org, int $serverId, int $siteId, int $deploymentId): array
    {
        return $this->get($this->sitePath($org, $serverId, $siteId)."/deployments/{$deploymentId}", [], 'fetch deployment');
    }

    /** @return list<array<string, mixed>> */
    public function deployments(string $org, int $serverId, int $siteId, int $limit = 10): array
    {
        return $this->page($this->sitePath($org, $serverId, $siteId).'/deployments', [], $limit, 'list deployments');
    }

    public function deploymentLog(string $org, int $serverId, int $siteId, int $deploymentId): string
    {
        return $this->text($this->sitePath($org, $serverId, $siteId)."/deployments/{$deploymentId}/log", 'output', 'fetch deployment log');
    }

    public function siteLog(string $org, int $serverId, int $siteId, string $type): string
    {
        $this->assertOneOf($type, ['application', 'nginx-access', 'nginx-error'], 'log type');

        return $this->text($this->sitePath($org, $serverId, $siteId).'/logs/'.$type, 'content', 'fetch site log');
    }

    public function environment(string $org, int $serverId, int $siteId): string
    {
        return $this->text($this->sitePath($org, $serverId, $siteId).'/environment', 'content', 'fetch environment');
    }

    public function updateEnvironment(string $org, int $serverId, int $siteId, string $contents): void
    {
        $this->send('PUT', $this->sitePath($org, $serverId, $siteId).'/environment', ['environment' => $contents], 'update environment');
    }

    public function nginxAction(string $org, int $serverId, string $action): void
    {
        $this->assertOneOf($action, ['reboot'], 'nginx action');
        $this->send('POST', $this->serverPath($org, $serverId).'/services/nginx/actions', ['action' => $action], 'run nginx action');
    }

    public function phpAction(string $org, int $serverId, string $action, string $version): void
    {
        $this->assertOneOf($action, ['reboot', 'reload'], 'php action');
        $this->send('POST', $this->serverPath($org, $serverId).'/services/php/actions', ['action' => $action, 'version' => $version], 'run php action');
    }

    /** @return list<array<string, mixed>> */
    public function backgroundProcesses(string $org, int $serverId): array
    {
        return $this->getAll($this->serverPath($org, $serverId).'/background-processes', [], 'list background processes');
    }

    /** @return array<string, mixed> */
    public function backgroundProcess(string $org, int $serverId, int $id): array
    {
        return $this->get($this->serverPath($org, $serverId)."/background-processes/{$id}", [], 'fetch background process');
    }

    public function backgroundProcessLog(string $org, int $serverId, int $id): string
    {
        return $this->text($this->serverPath($org, $serverId)."/background-processes/{$id}/log", 'content', 'fetch background process log');
    }

    public function backgroundProcessAction(string $org, int $serverId, int $id, string $action): void
    {
        $this->assertOneOf($action, ['restart', 'stop', 'start'], 'background process action');
        $this->send('POST', $this->serverPath($org, $serverId)."/background-processes/{$id}/actions", ['action' => $action], 'run background process action');
    }

    /**
     * @param  array<string, mixed>  $attrs  name, command, user, processes, site_id?, directory?
     * @return array<string, mixed>
     */
    public function createBackgroundProcess(string $org, int $serverId, array $attrs): array
    {
        $body = array_intersect_key($attrs, array_flip(['name', 'command', 'user', 'processes', 'site_id', 'directory']));

        return $this->send('POST', $this->serverPath($org, $serverId).'/background-processes', $body, 'create background process') ?? [];
    }

    public function updateBackgroundProcess(string $org, int $serverId, int $id, string $name, ?string $config): void
    {
        $body = ['name' => $name] + ($config !== null ? ['config' => $config] : []);

        $this->send('PUT', $this->serverPath($org, $serverId)."/background-processes/{$id}", $body, 'update background process');
    }

    public function deleteBackgroundProcess(string $org, int $serverId, int $id): void
    {
        $this->send('DELETE', $this->serverPath($org, $serverId)."/background-processes/{$id}", [], 'delete background process');
    }

    public function runCommand(string $org, int $serverId, int $siteId, string $command): void
    {
        $this->send('POST', $this->sitePath($org, $serverId, $siteId).'/commands', ['command' => $command], 'run command');
    }

    /** @return list<array<string, mixed>> */
    public function commands(string $org, int $serverId, int $siteId, int $limit = 5): array
    {
        return $this->page($this->sitePath($org, $serverId, $siteId).'/commands', ['sort' => '-created_at'], $limit, 'list commands');
    }

    /** @return array<string, mixed> */
    public function command(string $org, int $serverId, int $siteId, int $id): array
    {
        return $this->get($this->sitePath($org, $serverId, $siteId)."/commands/{$id}", [], 'fetch command');
    }

    public function commandOutput(string $org, int $serverId, int $siteId, int $id): string
    {
        return $this->text($this->sitePath($org, $serverId, $siteId)."/commands/{$id}/output", 'output', 'fetch command output');
    }

    public function deploymentScript(string $org, int $serverId, int $siteId): string
    {
        return $this->text($this->sitePath($org, $serverId, $siteId).'/deployments/script', 'content', 'fetch deployment script');
    }

    public function updateDeploymentScript(string $org, int $serverId, int $siteId, string $content): void
    {
        $this->send('PUT', $this->sitePath($org, $serverId, $siteId).'/deployments/script', ['content' => $content], 'update deployment script');
    }

    public function resetDeploymentStatus(string $org, int $serverId, int $siteId): void
    {
        $this->send('DELETE', $this->sitePath($org, $serverId, $siteId).'/deployments/status', [], 'reset deployment status');
    }

    /** Requires the Forge `site:manage-deploys` scope. */
    public function setPushToDeploy(string $org, int $serverId, int $siteId, bool $enabled): void
    {
        $this->send(
            $enabled ? 'POST' : 'DELETE',
            $this->sitePath($org, $serverId, $siteId).'/deployments/push-to-deploy',
            [],
            'update push to deploy',
        );
    }

    public function serverAction(string $org, int $serverId, string $action): void
    {
        // power-cycle is intentionally unsupported.
        $this->assertOneOf($action, ['reboot'], 'server action');
        $this->send('POST', $this->serverPath($org, $serverId).'/actions', ['action' => $action], 'run server action');
    }

    public function serviceAction(string $org, int $serverId, string $service, string $action): void
    {
        $this->assertOneOf($service, ['redis', 'supervisor', 'mysql', 'postgres'], 'service');
        $this->assertOneOf($action, ['reboot'], 'service action');
        $this->send('POST', $this->serverPath($org, $serverId)."/services/{$service}/actions", ['action' => $action], 'run service action');
    }

    /**
     * @return array<string, mixed> Flattened integration plus `processes` (included background processes).
     */
    public function integration(string $org, int $serverId, int $siteId, string $type): array
    {
        $this->assertOneOf($type, ['horizon', 'octane', 'reverb', 'laravel-maintenance'], 'integration');

        $body = $this->dispatch(
            fn () => $this->request()->get($this->sitePath($org, $serverId, $siteId).'/integrations/'.$type),
            'fetch integration',
        )->json();
        $body = is_array($body) ? $body : [];

        $processes = [];
        foreach ($body['included'] ?? [] as $included) {
            if (is_array($included)) {
                $processes[] = $this->flatten($included);
            }
        }

        return $this->flattenDocument($body) + ['processes' => $processes];
    }

    public function enableMaintenance(string $org, int $serverId, int $siteId, ?string $secret, int $status = 503, ?string $redirect = null): void
    {
        if (! in_array($status, [304, 307, 410, 503], true)) {
            throw new InvalidArgumentException("Unsupported maintenance status code {$status}; use one of 304, 307, 410, 503.");
        }

        $body = ['status' => $status]
            + ($secret !== null ? ['secret' => $secret] : [])
            + ($redirect !== null ? ['redirect' => $redirect] : []);

        $this->send('POST', $this->sitePath($org, $serverId, $siteId).'/integrations/laravel-maintenance', $body, 'enable maintenance mode');
    }

    public function disableMaintenance(string $org, int $serverId, int $siteId): void
    {
        $this->send('DELETE', $this->sitePath($org, $serverId, $siteId).'/integrations/laravel-maintenance', [], 'disable maintenance mode');
    }

    /** @return list<array<string, mixed>> */
    public function scheduledJobs(string $org, int $serverId, ?int $siteId = null): array
    {
        return $this->getAll($this->jobsPath($org, $serverId, $siteId), [], 'list scheduled jobs');
    }

    public function scheduledJobOutput(string $org, int $serverId, int $jobId, ?int $siteId = null): string
    {
        return $this->text($this->jobsPath($org, $serverId, $siteId)."/{$jobId}/output", 'output', 'fetch scheduled job output');
    }

    /** @return list<array<string, mixed>> */
    public function events(string $org, int $serverId, int $limit = 20): array
    {
        return $this->page($this->serverPath($org, $serverId).'/events', [], $limit, 'list events');
    }

    public function eventOutput(string $org, int $serverId, int $eventId): string
    {
        return $this->text($this->serverPath($org, $serverId)."/events/{$eventId}/output", 'output', 'fetch event output');
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

    private function orgPath(string $org): string
    {
        return '/orgs/'.rawurlencode($org);
    }

    private function serverPath(string $org, int $serverId): string
    {
        return $this->orgPath($org).'/servers/'.$serverId;
    }

    private function sitePath(string $org, int $serverId, int $siteId): string
    {
        return $this->serverPath($org, $serverId).'/sites/'.$siteId;
    }

    private function jobsPath(string $org, int $serverId, ?int $siteId): string
    {
        return ($siteId === null ? $this->serverPath($org, $serverId) : $this->sitePath($org, $serverId, $siteId)).'/scheduled-jobs';
    }

    /** @param  list<string>  $allowed */
    private function assertOneOf(string $value, array $allowed, string $label): void
    {
        if (! in_array($value, $allowed, true)) {
            throw new InvalidArgumentException("Unsupported {$label} '{$value}'; use one of: ".implode(', ', $allowed).'.');
        }
    }

    /** GET a document and return one string attribute ('' when absent). */
    private function text(string $path, string $attribute, string $action): string
    {
        $value = $this->get($path, [], $action)[$attribute] ?? '';

        return is_string($value) ? $value : '';
    }

    /**
     * GET a single page of a collection.
     *
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    private function page(string $path, array $query, int $limit, string $action): array
    {
        $body = $this->dispatch(
            fn () => $this->request()->get($path, $query + ['page[size]' => max(1, $limit)]),
            $action,
        )->json();

        $records = [];
        foreach (is_array($body) ? ($body['data'] ?? []) : [] as $resource) {
            if (is_array($resource)) {
                $records[] = $this->flatten($resource);
            }
        }

        return $records;
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
        $seen = [];

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
            if (! is_string($cursor) || $cursor === '' || isset($seen[$cursor])) {
                break;
            }
            $seen[$cursor] = true;
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

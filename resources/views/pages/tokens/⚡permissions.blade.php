<?php

use App\Forge\ForgeClientFactory;
use App\Forge\ForgeException;
use App\Forge\ForgeNotConnectedException;
use App\Models\ForgeConnection;
use App\Models\PermissionRule;
use App\Models\TokenDefault;
use App\Permissions\Capability;
use App\Permissions\PermissionEngine;
use App\Permissions\ScopeType;
use App\Permissions\Target;
use App\Permissions\Tier;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::app'), Title('Token permissions')] class extends Component {
    #[Locked]
    public int $tokenId = 0;

    /** @var array<string, bool> capability value => allowed globally */
    public array $global = [];

    /** @var array<string, bool> capability value => risky acknowledgement ticked */
    public array $ack = [];

    public string $overrideOrg = '';

    public string $overrideServer = '';

    public string $overrideSite = '';

    public string $overrideCapability = '';

    public string $overrideState = 'allow';

    public bool $overrideAck = false;

    public string $defaultOrg = '';

    public string $defaultServer = '';

    public string $defaultSite = '';

    public function mount(string $token): void
    {
        $this->tokenId = ctype_digit($token) ? (int) $token : 0;
        $this->accessToken(); // 404 for unknown tokens and tokens that belong to someone else
        $this->loadGlobal();
        $this->loadDefaults();
    }

    public function saveGlobal(PermissionEngine $engine): void
    {
        $this->resetErrorBag();
        $current = $this->currentGlobal();
        $changes = [];

        foreach (Capability::cases() as $capability) {
            $wanted = (bool) ($this->global[$capability->value] ?? false);

            if ($wanted === ($current[$capability->value] ?? false)) {
                continue;
            }

            if ($wanted && $capability->tier() === Tier::Risky && ! ($this->ack[$capability->value] ?? false)) {
                $this->addError("ack.{$capability->value}", __('Tick the acknowledgement to allow :capability.', ['capability' => $capability->label()]));

                continue;
            }

            $changes[] = [$capability, $wanted];
        }

        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        foreach ($changes as [$capability, $wanted]) {
            $engine->set($this->accessToken(), $capability, ScopeType::Global, new Target, $wanted ? true : null);
        }

        $this->ack = [];
        $this->loadGlobal();
        Flux::toast(__('Saved.'));
    }

    public function updatedOverrideOrg(): void
    {
        $this->overrideServer = '';
        $this->overrideSite = '';
    }

    public function updatedOverrideServer(): void
    {
        $this->overrideSite = '';
    }

    public function updatedOverrideSite(): void
    {
        $capability = Capability::tryFrom($this->overrideCapability);

        if ($this->overrideSite !== '' && $capability !== null && ! $capability->allowsSiteScope()) {
            $this->overrideCapability = '';
        }
    }

    public function updatedDefaultOrg(): void
    {
        $this->defaultServer = '';
        $this->defaultSite = '';
    }

    public function updatedDefaultServer(): void
    {
        $this->defaultSite = '';
    }

    public function saveDefaults(): void
    {
        $this->resetErrorBag();

        // Livewire payloads are client-editable: re-validate against exposed orgs and live Forge lists.
        if (collect($this->organizations)->firstWhere('slug', $this->defaultOrg) === null) {
            $this->addError('defaultOrg', __('Choose one of the exposed organizations.'));

            return;
        }

        if ($this->defaultServer === '') {
            $this->addError('defaultServer', __('Choose a server. A default site needs its server.'));

            return;
        }

        $lists = $this->defaultsForge;
        $server = collect($lists['servers'])->first(fn (array $s) => (string) $s['id'] === $this->defaultServer);

        if ($server === null) {
            $this->addError('defaultServer', $lists['error'] ?? __('That server is not in this organization.'));

            return;
        }

        $siteId = null;

        if ($this->defaultSite !== '') {
            $site = collect($lists['sites'])->first(fn (array $s) => (string) $s['id'] === $this->defaultSite);

            if ($site === null) {
                $this->addError('defaultSite', $lists['error'] ?? __('That site is not on this server.'));

                return;
            }

            $siteId = (int) $site['id'];
        }

        TokenDefault::query()->updateOrCreate(
            ['personal_access_token_id' => $this->accessToken()->getKey()],
            ['organization' => $this->defaultOrg, 'server_id' => (int) $server['id'], 'site_id' => $siteId],
        );

        unset($this->storedDefaults);
        Flux::toast(__('Saved.'));
    }

    public function clearDefaults(): void
    {
        TokenDefault::query()->where('personal_access_token_id', $this->accessToken()->getKey())->delete();

        $this->defaultOrg = '';
        $this->defaultServer = '';
        $this->defaultSite = '';
        unset($this->storedDefaults);
        Flux::toast(__('Defaults cleared.'));
    }

    public function saveOverride(PermissionEngine $engine): void
    {
        $this->resetErrorBag();

        // Livewire payloads are client-editable: every value is re-validated against exposed orgs and live Forge data.
        $organization = collect($this->organizations)->firstWhere('slug', $this->overrideOrg);
        $capability = Capability::tryFrom($this->overrideCapability);
        $state = ['inherit' => null, 'allow' => true, 'deny' => false];

        if ($organization === null) {
            $this->addError('overrideOrg', __('Choose one of the exposed organizations.'));
        }

        if ($capability === null) {
            $this->addError('overrideCapability', __('Choose a capability.'));
        }

        if (! array_key_exists($this->overrideState, $state)) {
            $this->addError('overrideState', __('Choose inherit, allow or deny.'));
        }

        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        $serverId = null;
        $siteId = null;

        if ($this->overrideServer === '' && $this->overrideSite !== '') {
            $this->addError('overrideServer', __('Choose a server first.'));

            return;
        }

        $forge = $this->forge;

        if ($this->overrideServer !== '') {
            $server = collect($forge['servers'])->first(fn (array $s) => (string) $s['id'] === $this->overrideServer);

            if ($server === null) {
                $this->addError('overrideServer', $forge['error'] ?? __('That server is not in this organization.'));

                return;
            }

            $serverId = (int) $server['id'];
        }

        if ($this->overrideSite !== '') {
            $site = collect($forge['sites'])->first(fn (array $s) => (string) $s['id'] === $this->overrideSite);

            if ($site === null) {
                $this->addError('overrideSite', $forge['error'] ?? __('That site is not on this server.'));

                return;
            }

            $siteId = (int) $site['id'];
        }

        $scope = match (true) {
            $siteId !== null => ScopeType::Site,
            $serverId !== null => ScopeType::Server,
            default => ScopeType::Organization,
        };

        if ($scope === ScopeType::Site && ! $capability->allowsSiteScope()) {
            $this->addError('overrideCapability', __(':capability cannot be set per site.', ['capability' => $capability->label()]));

            return;
        }

        if ($state[$this->overrideState] === true && $capability->tier() === Tier::Risky && ! $this->overrideAck) {
            $this->addError('overrideAck', __('Tick the acknowledgement to allow :capability.', ['capability' => $capability->label()]));

            return;
        }

        try {
            $engine->set($this->accessToken(), $capability, $scope, new Target($organization['slug'], $serverId, $siteId), $state[$this->overrideState]);
        } catch (InvalidArgumentException $e) {
            $this->addError('overrideCapability', $e->getMessage());

            return;
        }

        $this->overrideAck = false;
        Flux::toast(__('Saved.'));
    }

    public function removeOverride(int $ruleId, PermissionEngine $engine): void
    {
        PermissionRule::query()
            ->where('personal_access_token_id', $this->accessToken()->getKey())
            ->where('scope_type', '!=', ScopeType::Global->value)
            ->whereKey($ruleId)
            ->delete();

        $engine->flush();
    }

    #[Computed]
    public function connection(): ?ForgeConnection
    {
        return Auth::user()->forgeConnection()->first();
    }

    /** @return list<array{slug: string, name: string}> Organizations exposed on the Forge connection. */
    #[Computed]
    public function organizations(): array
    {
        $connection = $this->connection;

        if ($connection === null) {
            return [];
        }

        return array_values(array_filter(
            $connection->organizations,
            fn (array $org) => $connection->exposes($org['slug']),
        ));
    }

    /** @return array{servers: list<array<string, mixed>>, sites: list<array<string, mixed>>, error: string|null} */
    #[Computed]
    public function forge(): array
    {
        $result = ['servers' => [], 'sites' => [], 'error' => null];

        if (collect($this->organizations)->firstWhere('slug', $this->overrideOrg) === null) {
            return $result;
        }

        $what = 'servers';

        try {
            $client = app(ForgeClientFactory::class)->forUser(Auth::user());
            $result['servers'] = $client->servers($this->overrideOrg);

            $server = collect($result['servers'])->first(fn (array $s) => (string) $s['id'] === $this->overrideServer);

            if ($server !== null) {
                $what = 'sites';
                $result['sites'] = $client->sites($this->overrideOrg, (int) $server['id']);
            }
        } catch (ForgeException $e) {
            $result['error'] = __('Could not load :what: :message', ['what' => $what, 'message' => $e->agentMessage()]);
        } catch (ForgeNotConnectedException $e) {
            $result['error'] = $e->getMessage();
        }

        return $result;
    }

    /** @return array{servers: list<array<string, mixed>>, sites: list<array<string, mixed>>, error: string|null} */
    #[Computed]
    public function defaultsForge(): array
    {
        $result = ['servers' => [], 'sites' => [], 'error' => null];

        if (collect($this->organizations)->firstWhere('slug', $this->defaultOrg) === null) {
            return $result;
        }

        $what = 'servers';

        try {
            $client = app(ForgeClientFactory::class)->forUser(Auth::user());
            $result['servers'] = $client->servers($this->defaultOrg);

            $server = collect($result['servers'])->first(fn (array $s) => (string) $s['id'] === $this->defaultServer);

            if ($server !== null) {
                $what = 'sites';
                $result['sites'] = $client->sites($this->defaultOrg, (int) $server['id']);
            }
        } catch (ForgeException $e) {
            $result['error'] = __('Could not load :what: :message', ['what' => $what, 'message' => $e->agentMessage()]);
        } catch (ForgeNotConnectedException $e) {
            $result['error'] = $e->getMessage();
        }

        return $result;
    }

    /** The stored defaults described for display, or null when none are stored. */
    #[Computed]
    public function storedDefaults(): ?string
    {
        $row = TokenDefault::query()->where('personal_access_token_id', $this->tokenId)->first();

        if ($row === null) {
            return null;
        }

        $parts = [$row->organization];
        $names = ['server' => null, 'site' => null];

        if ($this->connection?->exposes($row->organization)) {
            try {
                $client = app(ForgeClientFactory::class)->forUser(Auth::user());
                $names['server'] = collect($client->servers($row->organization))->firstWhere('id', $row->server_id)['name'] ?? null;
                $names['site'] = $row->server_id !== null && $row->site_id !== null
                    ? (collect($client->sites($row->organization, $row->server_id))->firstWhere('id', $row->site_id)['name'] ?? null)
                    : null;
            } catch (ForgeException|ForgeNotConnectedException) {
                // Show bare ids.
            }
        }

        if ($row->server_id !== null) {
            $parts[] = 'server '.($names['server'] !== null ? "{$names['server']} ({$row->server_id})" : $row->server_id);
        }

        if ($row->site_id !== null) {
            $parts[] = 'site '.($names['site'] !== null ? "{$names['site']} ({$row->site_id})" : $row->site_id);
        }

        return implode(' / ', $parts);
    }

    /** @return list<Capability> */
    #[Computed]
    public function overrideCapabilities(): array
    {
        return array_values(array_filter(
            Capability::cases(),
            fn (Capability $c) => $this->overrideSite === '' || $c->allowsSiteScope(),
        ));
    }

    /** @return list<array{id: int, capability: string, state: string, where: string}> */
    #[Computed]
    public function overrides(): array
    {
        return PermissionRule::query()
            ->where('personal_access_token_id', $this->accessToken()->getKey())
            ->where('scope_type', '!=', ScopeType::Global->value)
            ->orderBy('organization')->orderBy('server_id')->orderBy('site_id')->orderBy('id')
            ->get()
            ->map(fn (PermissionRule $rule) => [
                'id' => $rule->id,
                'capability' => $rule->capability->label(),
                'state' => $rule->allowed ? __('Allow') : __('Deny'),
                'where' => match ($rule->scope_type) {
                    ScopeType::Site => "{$rule->organization} / server {$rule->server_id} / site {$rule->site_id}",
                    ScopeType::Server => "{$rule->organization} / server {$rule->server_id}",
                    default => (string) $rule->organization,
                },
            ])->all();
    }

    /** @return array<string, list<Capability>> */
    #[Computed]
    public function tiers(): array
    {
        $groups = [];

        foreach (Capability::cases() as $capability) {
            $groups[$capability->tier()->name][] = $capability;
        }

        return $groups;
    }

    #[Computed]
    public function tokenName(): string
    {
        return $this->accessToken()->name;
    }

    #[Computed]
    public function summary(): string
    {
        app(PermissionEngine::class)->flush();

        return app(PermissionEngine::class)->summary($this->accessToken());
    }

    public function acknowledgement(Capability $capability): string
    {
        return __('I understand this lets agents :what', ['what' => rtrim(lcfirst($capability->description()), '.').'.']);
    }

    private function accessToken(): PersonalAccessToken
    {
        return Auth::user()->tokens()->findOrFail($this->tokenId);
    }

    /** @return array<string, bool> */
    private function currentGlobal(): array
    {
        return PermissionRule::query()
            ->where('personal_access_token_id', $this->tokenId)
            ->where('scope_type', ScopeType::Global->value)
            ->where('allowed', true)
            ->get()
            ->mapWithKeys(fn (PermissionRule $rule) => [$rule->capability->value => true])
            ->all();
    }

    private function loadDefaults(): void
    {
        $row = TokenDefault::query()->where('personal_access_token_id', $this->tokenId)->first();

        $this->defaultOrg = $row?->organization ?? '';
        $this->defaultServer = $row?->server_id !== null ? (string) $row->server_id : '';
        $this->defaultSite = $row?->site_id !== null ? (string) $row->site_id : '';
    }

    private function loadGlobal(): void
    {
        $current = $this->currentGlobal();
        $this->global = [];

        foreach (Capability::cases() as $capability) {
            $this->global[$capability->value] = $current[$capability->value] ?? false;
        }
    }
};
?>

<div class="mx-auto flex w-full max-w-4xl flex-col gap-8 p-6">
    <div>
        <flux:button :href="route('tokens.index')" variant="subtle" icon="arrow-left" size="sm" wire:navigate>{{ __('Access tokens') }}</flux:button>
        <flux:heading size="xl" class="mt-2">{{ $this->tokenName }}</flux:heading>
        <flux:text class="mt-1">{{ $this->summary }}</flux:text>
    </div>

    @if ($this->connection === null)
        <flux:callout variant="warning" icon="exclamation-triangle" data-test="not-connected">
            <flux:callout.heading>{{ __('Forge is not connected.') }}</flux:callout.heading>
            <flux:callout.text>{{ __('Global permissions are saved, but nothing is reachable until you connect Forge.') }}</flux:callout.text>
            <x-slot name="actions"><flux:button :href="route('forge.connection')" wire:navigate>{{ __('Connect Forge') }}</flux:button></x-slot>
        </flux:callout>
    @elseif ($this->organizations === [])
        <flux:callout variant="warning" icon="exclamation-triangle" data-test="no-orgs">
            <flux:callout.heading>{{ __('No organization is exposed.') }}</flux:callout.heading>
            <flux:callout.text>{{ __('Agents cannot reach anything until you expose an organization on the Forge connection page.') }}</flux:callout.text>
            <x-slot name="actions"><flux:button :href="route('forge.connection')" wire:navigate>{{ __('Expose an organization') }}</flux:button></x-slot>
        </flux:callout>
    @endif

    <form wire:submit="saveGlobal" class="flex flex-col gap-6">
        <div>
            <flux:heading size="lg">{{ __('Global permissions') }}</flux:heading>
            <flux:text>{{ __('Apply everywhere this token can reach. Overrides below take precedence.') }}</flux:text>
        </div>

        @foreach ($this->tiers as $tier => $capabilities)
            <div class="flex flex-col gap-4" wire:key="tier-{{ $tier }}">
                <flux:heading>{{ match ($tier) { 'Read' => __('Read'), 'Action' => __('Actions'), default => __('Risky') } }}</flux:heading>

                @foreach ($capabilities as $capability)
                    <div class="flex flex-col gap-2" wire:key="global-{{ $capability->value }}">
                        <flux:switch wire:model="global.{{ $capability->value }}" :label="$capability->label()" :description="$capability->description()" />

                        @if ($capability->tier() === \App\Permissions\Tier::Risky)
                            <div x-show="$wire.global['{{ $capability->value }}']" class="ps-12">
                                <flux:checkbox wire:model="ack.{{ $capability->value }}" :label="$this->acknowledgement($capability)" />
                                <flux:error name="ack.{{ $capability->value }}" />
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endforeach

        <div>
            <flux:button type="submit" variant="primary" data-test="save-global-button">{{ __('Save global permissions') }}</flux:button>
        </div>
    </form>

    @if ($this->organizations !== [])
        <form wire:submit="saveDefaults" class="flex flex-col gap-4" data-test="defaults-card">
            <div>
                <flux:heading size="lg">{{ __('Defaults') }}</flux:heading>
                <flux:text>{{ __('Used when an agent omits the organization, server or site. Project `.mcp.json` headers override these.') }}</flux:text>
                <flux:text class="mt-1" data-test="stored-defaults">
                    {{ $this->storedDefaults !== null ? __('Current: :defaults', ['defaults' => $this->storedDefaults]) : __('No defaults stored.') }}
                </flux:text>
            </div>

            @if ($this->defaultsForge['error'])
                <flux:callout variant="danger" icon="exclamation-triangle" :heading="$this->defaultsForge['error']" data-test="defaults-forge-error" />
            @endif

            <div class="grid gap-4 sm:grid-cols-3">
                <div>
                    <flux:select wire:model.live="defaultOrg" :label="__('Organization')">
                        <option value="">{{ __('Choose…') }}</option>
                        @foreach ($this->organizations as $org)
                            <option value="{{ $org['slug'] }}">{{ $org['name'] }}</option>
                        @endforeach
                    </flux:select>
                    <flux:error name="defaultOrg" />
                </div>

                <div>
                    <flux:select wire:model.live="defaultServer" :label="__('Server')" :disabled="$defaultOrg === ''">
                        <option value="">{{ __('Choose…') }}</option>
                        @foreach ($this->defaultsForge['servers'] as $server)
                            <option value="{{ $server['id'] }}">{{ $server['name'] ?? $server['id'] }}</option>
                        @endforeach
                    </flux:select>
                    <flux:error name="defaultServer" />
                </div>

                <div>
                    <flux:select wire:model.live="defaultSite" :label="__('Site (optional)')" :disabled="$defaultServer === ''">
                        <option value="">{{ __('No default site') }}</option>
                        @foreach ($this->defaultsForge['sites'] as $site)
                            <option value="{{ $site['id'] }}">{{ $site['name'] ?? $site['id'] }}</option>
                        @endforeach
                    </flux:select>
                    <flux:error name="defaultSite" />
                </div>
            </div>

            <div class="flex gap-2">
                <flux:button type="submit" variant="primary" data-test="save-defaults-button">{{ __('Save defaults') }}</flux:button>
                @if ($this->storedDefaults !== null)
                    <flux:button wire:click="clearDefaults" data-test="clear-defaults-button">{{ __('Clear') }}</flux:button>
                @endif
            </div>
        </form>
    @endif

    @if ($this->organizations !== [])
        <form wire:submit="saveOverride" class="flex flex-col gap-4">
            <div>
                <flux:heading size="lg">{{ __('Overrides') }}</flux:heading>
                <flux:text>{{ __('Allow or deny a capability for one organization, server or site. Inherit removes the override. The most specific rule wins.') }}</flux:text>
            </div>

            @if ($this->forge['error'])
                <flux:callout variant="danger" icon="exclamation-triangle" :heading="$this->forge['error']" data-test="forge-error" />
            @endif

            <div class="grid gap-4 sm:grid-cols-3">
                <div>
                    <flux:select wire:model.live="overrideOrg" :label="__('Organization')">
                        <option value="">{{ __('Choose…') }}</option>
                        @foreach ($this->organizations as $org)
                            <option value="{{ $org['slug'] }}" data-override-org="{{ $org['slug'] }}">{{ $org['name'] }}</option>
                        @endforeach
                    </flux:select>
                    <flux:error name="overrideOrg" />
                </div>

                <div>
                    <flux:select wire:model.live="overrideServer" :label="__('Server (optional)')" :disabled="$overrideOrg === ''">
                        <option value="">{{ __('Whole organization') }}</option>
                        @foreach ($this->forge['servers'] as $server)
                            <option value="{{ $server['id'] }}">{{ $server['name'] ?? $server['id'] }}</option>
                        @endforeach
                    </flux:select>
                    <flux:error name="overrideServer" />
                </div>

                <div>
                    <flux:select wire:model.live="overrideSite" :label="__('Site (optional)')" :disabled="$overrideServer === ''">
                        <option value="">{{ __('Whole server') }}</option>
                        @foreach ($this->forge['sites'] as $site)
                            <option value="{{ $site['id'] }}">{{ $site['name'] ?? $site['id'] }}</option>
                        @endforeach
                    </flux:select>
                    <flux:error name="overrideSite" />
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <flux:select wire:model.live="overrideCapability" :label="__('Capability')">
                        <option value="">{{ __('Choose…') }}</option>
                        @foreach ($this->overrideCapabilities as $capability)
                            <option value="{{ $capability->value }}" data-override-option="{{ $capability->value }}">{{ $capability->label() }}</option>
                        @endforeach
                    </flux:select>
                    <flux:error name="overrideCapability" />
                </div>

                <div>
                    <flux:select wire:model.live="overrideState" :label="__('Rule')">
                        <option value="inherit">{{ __('Inherit') }}</option>
                        <option value="allow">{{ __('Allow') }}</option>
                        <option value="deny">{{ __('Deny') }}</option>
                    </flux:select>
                    <flux:error name="overrideState" />
                </div>
            </div>

            @php($pending = \App\Permissions\Capability::tryFrom($overrideCapability))
            @if ($pending && $pending->tier() === \App\Permissions\Tier::Risky && $overrideState === 'allow')
                <div>
                    <flux:checkbox wire:model="overrideAck" :label="$this->acknowledgement($pending)" />
                    <flux:error name="overrideAck" />
                </div>
            @endif

            <div>
                <flux:button type="submit" variant="primary" data-test="save-override-button">{{ __('Save override') }}</flux:button>
            </div>
        </form>
    @endif

    @if ($this->overrides !== [])
        <div class="flex flex-col gap-2">
            <flux:heading size="lg">{{ __('Current overrides') }}</flux:heading>

            @foreach ($this->overrides as $override)
                <div class="flex items-center justify-between gap-4 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700" wire:key="override-{{ $override['id'] }}">
                    <div class="min-w-0 text-sm">
                        <span class="font-medium">{{ $override['capability'] }}</span>
                        <span>: {{ $override['state'] }}</span>
                        <span class="block truncate text-zinc-500">{{ $override['where'] }}</span>
                    </div>
                    <flux:button size="sm" wire:click="removeOverride({{ $override['id'] }})">{{ __('Remove') }}</flux:button>
                </div>
            @endforeach
        </div>
    @endif
</div>

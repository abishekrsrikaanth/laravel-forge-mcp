<?php

use App\Forge\ForgeClient;
use App\Forge\ForgeException;
use App\Models\ForgeConnection;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::app'), Title('Forge connection')] class extends Component {
    public string $token = '';

    /** @var list<string> */
    public array $exposed = [];

    /** @var array<string, string> Forge token scope => what it enables */
    public array $scopes = [
        'server:view' => 'Browse servers and sites, check deploy status, and read .env so secrets can be scrubbed from output.',
        'server:manage-logs' => 'Read site logs.',
        'site:manage-deploys' => 'Deploy, read deployment logs and the deployment script, and toggle quick deploy.',
        'server:manage-services' => 'Restart services and reboot servers.',
        'server:create-daemons' => 'Create queue workers (background processes).',
        'server:delete-daemons' => 'Delete queue workers (background processes).',
        'site:manage-environment' => 'Edit the .env file.',
        'site:manage-commands' => 'Run commands and toggle maintenance mode.',
    ];

    public function mount(): void
    {
        $this->exposed = $this->connection()?->exposedOrganizations() ?? [];
    }

    public function connect(): void
    {
        $token = trim($this->token);

        $this->validate(['token' => ['required', 'string', 'min:8', 'max:2048']]);

        try {
            $client = new ForgeClient($token);
            $me = $client->me();
            $organizations = array_map(
                fn (array $org) => ['slug' => (string) ($org['slug'] ?? ''), 'name' => (string) ($org['name'] ?? $org['slug'] ?? '')],
                $client->organizations(),
            );
        } catch (ForgeException $e) {
            $this->token = '';
            $this->addError('token', $e->status === 401 ? 'Forge rejected this token.' : 'Could not validate the token with Forge: '.$e->agentMessage());

            return;
        }

        $organizations = array_values(array_filter($organizations, fn (array $org) => $org['slug'] !== ''));
        $exposed = count($organizations) === 1 ? [$organizations[0]['slug']] : [];

        $previous = $this->connection();
        ForgeClient::flushCache();

        // Keep a prior selection (limited to orgs the new token can see); otherwise preselect a lone org.
        $kept = $previous === null
            ? []
            : array_values(array_intersect($previous->exposedOrganizations(), array_column($organizations, 'slug')));

        Auth::user()->forgeConnection()->updateOrCreate([], [
            'token' => $token,
            'token_last_four' => substr($token, -4),
            'forge_user_name' => (string) ($me['name'] ?? ''),
            'forge_user_email' => isset($me['email']) ? (string) $me['email'] : null,
            'organizations' => $organizations,
            'exposed_organizations' => $kept === [] ? $exposed : $kept,
            'last_error' => null,
            'validated_at' => now(),
        ]);

        $this->token = '';
        $this->exposed = $this->connection()?->exposedOrganizations() ?? [];
    }

    public function saveExposure(): void
    {
        $connection = $this->connection();

        if ($connection === null) {
            return;
        }

        $known = array_column($connection->organizations, 'slug');
        $connection->update([
            'exposed_organizations' => array_values(array_intersect($known, $this->exposed)),
        ]);

        ForgeClient::flushCache();
        $this->exposed = $connection->exposedOrganizations();
        Flux::toast(__('Saved.'));
    }

    public function disconnect(): void
    {
        $this->connection()?->delete();
        ForgeClient::flushCache();
        $this->exposed = [];
    }

    public function connection(): ?ForgeConnection
    {
        return Auth::user()->forgeConnection()->first();
    }
};
?>

<div class="mx-auto flex w-full max-w-3xl flex-col gap-8 p-6">
    <div>
        <flux:heading size="xl">{{ __('Forge connection') }}</flux:heading>
        <flux:text class="mt-1">{{ __('Your Forge API token is stored encrypted and is never shown again.') }}</flux:text>
    </div>

    @php($connection = $this->connection())

    @if ($connection)
        <flux:card class="flex flex-col gap-4">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <flux:heading>{{ $connection->forge_user_name }}</flux:heading>
                    <flux:text>{{ $connection->forge_user_email }}</flux:text>
                    <flux:text class="font-mono">•••• {{ $connection->token_last_four }}</flux:text>
                    @if ($connection->validated_at)
                        <flux:text size="sm">{{ __('Validated :time', ['time' => $connection->validated_at->diffForHumans()]) }}</flux:text>
                    @endif
                </div>
                <flux:button wire:click="disconnect" wire:confirm="{{ __('Disconnect Forge and delete the stored token?') }}" variant="danger" data-test="disconnect-button">
                    {{ __('Disconnect') }}
                </flux:button>
            </div>

            @if ($connection->last_error)
                <flux:callout variant="danger" icon="exclamation-triangle" :heading="$connection->last_error" />
            @endif
        </flux:card>

        <form wire:submit="saveExposure" class="flex flex-col gap-4">
            <div>
                <flux:heading size="lg">{{ __('Exposed organizations') }}</flux:heading>
                <flux:text>{{ __('MCP clients can only reach the organizations selected here.') }}</flux:text>
            </div>

            <flux:checkbox.group wire:model="exposed">
                @foreach ($connection->organizations as $org)
                    <flux:checkbox :value="$org['slug']" :label="$org['name']" :description="$org['slug']" />
                @endforeach
            </flux:checkbox.group>

            <div>
                <flux:button type="submit" variant="primary" data-test="save-exposure-button">{{ __('Save') }}</flux:button>
            </div>
        </form>
    @endif

    <form wire:submit="connect" class="flex flex-col gap-4">
        <flux:heading size="lg">{{ $connection ? __('Replace token') : __('Connect to Forge') }}</flux:heading>

        <flux:input wire:model="token" :label="__('Forge API token')" type="password" autocomplete="off" required />

        <div>
            <flux:button type="submit" variant="primary" data-test="connect-button">
                {{ $connection ? __('Replace token') : __('Connect to Forge') }}
            </flux:button>
        </div>
    </form>

    <div class="flex flex-col gap-3">
        <div>
            <flux:heading size="lg">{{ __('Required token scopes') }}</flux:heading>
            <flux:text>{{ __('Grant the token only the scopes for the tools you want to use. Reading .env for secret scrubbing needs server:view.') }}</flux:text>
        </div>

        <dl class="flex flex-col gap-2">
            @foreach ($scopes as $scope => $description)
                <div class="flex flex-col gap-1 sm:flex-row sm:gap-4">
                    <dt class="font-mono text-sm sm:w-64 sm:shrink-0">{{ $scope }}</dt>
                    <dd class="text-sm text-zinc-600 dark:text-zinc-400">{{ $description }}</dd>
                </div>
            @endforeach
        </dl>
    </div>
</div>

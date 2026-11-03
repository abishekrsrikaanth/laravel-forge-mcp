<?php

use App\Mcp\AccessTokens;
use App\Permissions\PermissionEngine;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::app'), Title('Access tokens')] class extends Component {
    public string $name = '';

    public string $copyFrom = '';

    /** Shown exactly once: cleared at the start of the next request so it never outlives the creation response. */
    public ?string $plainTextToken = null;

    public function hydrate(): void
    {
        $this->plainTextToken = null;
    }

    public function create(AccessTokens $tokens): void
    {
        $this->validate(['name' => ['required', 'string', 'max:100'], 'copyFrom' => ['nullable', 'string']]);

        $source = null;

        if ($this->copyFrom !== '') {
            $source = Auth::user()->tokens()->whereKey($this->copyFrom)->first();

            if ($source === null) {
                $this->addError('copyFrom', __('Choose one of your own tokens.'));

                return;
            }
        }

        try {
            $new = $tokens->create(Auth::user(), trim($this->name), $source);
        } catch (AuthorizationException) {
            $this->addError('copyFrom', __('Choose one of your own tokens.'));

            return;
        }

        $this->plainTextToken = $new->plainTextToken;
        $this->reset('name', 'copyFrom');
        unset($this->accessTokens);
    }

    public function revoke(int $id): void
    {
        Auth::user()->tokens()->whereKey($id)->first()?->delete();
        app(PermissionEngine::class)->flush();
        unset($this->accessTokens);
    }

    /** @return list<array{id: int, name: string, last_used: string, summary: string}> */
    #[Computed]
    public function accessTokens(): array
    {
        $engine = app(PermissionEngine::class);
        $engine->flush();

        return Auth::user()->tokens()->orderBy('id')->get()->map(fn ($token) => [
            'id' => (int) $token->getKey(),
            'name' => $token->name,
            'last_used' => $token->last_used_at?->diffForHumans() ?? __('Never used'),
            'summary' => $engine->summary($token),
        ])->all();
    }

    /** @return array{command: string, json: string} */
    #[Computed]
    public function snippets(): array
    {
        $url = rtrim((string) config('app.url'), '/').'/mcp';
        $token = $this->plainTextToken ?? '<token>';

        return [
            'command' => 'claude mcp add --transport http forge-mcp '.$url.' --header "Authorization: Bearer '.$token.'"',
            'json' => json_encode(
                ['mcpServers' => ['forge-mcp' => ['type' => 'http', 'url' => $url, 'headers' => ['Authorization' => 'Bearer '.$token]]]],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ),
        ];
    }
};
?>

<div class="mx-auto flex w-full max-w-4xl flex-col gap-8 p-6">
    <div>
        <flux:heading size="xl">{{ __('Access tokens') }}</flux:heading>
        <flux:text class="mt-1">{{ __('Create one token per agent or device. New tokens are read-only until you grant more.') }}</flux:text>
    </div>

    @if ($plainTextToken)
        <flux:callout variant="success" icon="key" data-test="new-token">
            <flux:callout.heading>{{ __('Copy your new token now. It will not be shown again.') }}</flux:callout.heading>
            <flux:callout.text>
                <code class="break-all font-mono text-sm" data-test="plain-text-token">{{ $plainTextToken }}</code>
            </flux:callout.text>
        </flux:callout>
    @endif

    <form wire:submit="create" class="flex flex-col gap-4">
        <flux:heading size="lg">{{ __('New token') }}</flux:heading>

        <flux:input wire:model="name" :label="__('Name')" :placeholder="__('e.g. Laptop - Claude Code')" required />

        @if (count($this->accessTokens) > 0)
            <flux:select wire:model="copyFrom" :label="__('Copy permissions from')">
                <flux:select.option value="">{{ __('Start read-only') }}</flux:select.option>
                @foreach ($this->accessTokens as $existing)
                    <flux:select.option :value="$existing['id']">{{ $existing['name'] }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:error name="copyFrom" />
        @endif

        <div>
            <flux:button type="submit" variant="primary" data-test="create-token-button">{{ __('Create token') }}</flux:button>
        </div>
    </form>

    <div class="flex flex-col gap-3">
        <flux:heading size="lg">{{ __('Your tokens') }}</flux:heading>

        @forelse ($this->accessTokens as $accessToken)
            <flux:card wire:key="token-{{ $accessToken['id'] }}" class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="min-w-0">
                    <flux:heading>{{ $accessToken['name'] }}</flux:heading>
                    <flux:text size="sm">{{ $accessToken['last_used'] }}</flux:text>
                    <flux:text size="sm">{{ $accessToken['summary'] }}</flux:text>
                </div>
                <div class="flex gap-2">
                    <flux:button :href="route('tokens.permissions', $accessToken['id'])" wire:navigate>{{ __('Permissions') }}</flux:button>
                    <flux:button wire:click="revoke({{ $accessToken['id'] }})" wire:confirm="{{ __('Revoke this token? Agents using it lose access immediately.') }}" variant="danger">
                        {{ __('Revoke') }}
                    </flux:button>
                </div>
            </flux:card>
        @empty
            <flux:text>{{ __('No tokens yet.') }}</flux:text>
        @endforelse
    </div>

    <div class="flex flex-col gap-3">
        <flux:heading size="lg">{{ __('Connect a client') }}</flux:heading>
        <flux:text>{{ __('Claude Code:') }}</flux:text>
        <pre class="overflow-x-auto rounded-lg bg-zinc-100 p-3 text-sm dark:bg-zinc-800" data-test="snippet-command">{{ $this->snippets['command'] }}</pre>
        <flux:text>{{ __('Any other MCP client (JSON config):') }}</flux:text>
        <pre class="overflow-x-auto rounded-lg bg-zinc-100 p-3 text-sm dark:bg-zinc-800" data-test="snippet-json">{{ $this->snippets['json'] }}</pre>
    </div>
</div>

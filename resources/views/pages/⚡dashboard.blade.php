<?php

use App\Models\AuditEntry;
use App\Models\ForgeConnection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::app'), Title('Dashboard')] class extends Component {
    #[Computed]
    public function connection(): ?ForgeConnection
    {
        return ForgeConnection::where('user_id', Auth::id())->first();
    }

    #[Computed]
    public function activeTokens(): int
    {
        return Auth::user()->tokens()->count();
    }

    /** @return Collection<int, AuditEntry> */
    #[Computed]
    public function recent(): Collection
    {
        return AuditEntry::where('user_id', Auth::id())->orderByDesc('created_at')->orderByDesc('id')->limit(10)->get();
    }
};
?>

<div class="mx-auto flex w-full max-w-5xl flex-col gap-6 p-6">
    <flux:heading size="xl">{{ __('Dashboard') }}</flux:heading>

    <div class="grid gap-4 md:grid-cols-2">
        <flux:card class="flex flex-col gap-2" data-test="forge-status">
            <flux:heading>{{ __('Forge connection') }}</flux:heading>
            @if ($this->connection === null)
                <flux:text>{{ __('No Forge account is connected yet.') }}</flux:text>
                <div><flux:button variant="primary" :href="route('forge.connection')" wire:navigate>{{ __('Connect Forge') }}</flux:button></div>
            @elseif ($this->connection->last_error)
                <flux:callout variant="danger" icon="exclamation-triangle" data-test="forge-error">
                    <flux:callout.heading>{{ __('Forge reported a problem') }}</flux:callout.heading>
                    <flux:callout.text>{{ $this->connection->last_error }}</flux:callout.text>
                </flux:callout>
                <div><flux:button :href="route('forge.connection')" wire:navigate>{{ __('Reconnect') }}</flux:button></div>
            @else
                <flux:text>{{ __('Connected as :name', ['name' => $this->connection->forge_user_name]) }}</flux:text>
            @endif
        </flux:card>

        <flux:card class="flex flex-col gap-2">
            <flux:heading>{{ __('Active MCP tokens') }}</flux:heading>
            <div class="text-3xl font-semibold" data-test="active-tokens">{{ $this->activeTokens }}</div>
            <div><flux:button :href="route('tokens.index')" wire:navigate>{{ __('Manage tokens') }}</flux:button></div>
        </flux:card>
    </div>

    <flux:card class="flex flex-col gap-3">
        <div class="flex items-center justify-between">
            <flux:heading>{{ __('Recent activity') }}</flux:heading>
            <flux:link :href="route('audit.index')" wire:navigate>{{ __('View audit log') }}</flux:link>
        </div>

        @forelse ($this->recent as $entry)
            <div wire:key="recent-{{ $entry->id }}" class="flex items-center justify-between gap-3 text-sm">
                <div class="min-w-0">
                    <span class="font-mono">{{ $entry->tool }}</span>
                    <span class="text-zinc-500">{{ $entry->summary }}</span>
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    <flux:badge size="sm" :color="match ($entry->outcome) { 'success' => 'green', 'denied' => 'amber', default => 'red' }">{{ $entry->outcome }}</flux:badge>
                    <span class="text-zinc-500">{{ $entry->created_at?->diffForHumans() }}</span>
                </div>
            </div>
        @empty
            <flux:text>{{ __('No tool calls yet.') }}</flux:text>
        @endforelse
    </flux:card>
</div>

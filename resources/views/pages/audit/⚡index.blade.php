<?php

use App\Models\AuditEntry;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::app'), Title('Audit log')] class extends Component {
    use WithPagination;

    #[Url(except: '')]
    public string $tool = '';

    #[Url(except: '')]
    public string $outcome = '';

    #[Url(except: '')]
    public string $token = '';

    /** Livewire properties are client-editable: reset anything that is not an allowed option. */
    public function updated(string $property): void
    {
        if ($property === 'outcome' && ! in_array($this->outcome, ['', ...$this->outcomes], true)) {
            $this->outcome = '';
        }

        if ($property === 'tool' && ! in_array($this->tool, ['', ...$this->tools], true)) {
            $this->tool = '';
        }

        if ($property === 'token' && ! in_array($this->token, ['', ...$this->tokenNames], true)) {
            $this->token = '';
        }

        $this->resetPage();
    }

    /** @return list<string> */
    #[Computed]
    public function outcomes(): array
    {
        return [AuditEntry::SUCCESS, AuditEntry::DENIED, AuditEntry::ERROR];
    }

    /** @return list<string> */
    #[Computed]
    public function tools(): array
    {
        return $this->distinct('tool');
    }

    /** @return list<string> */
    #[Computed]
    public function tokenNames(): array
    {
        return $this->distinct('token_name');
    }

    /** @return LengthAwarePaginator<int, AuditEntry> */
    #[Computed]
    public function entries(): LengthAwarePaginator
    {
        // Re-validate on every render: URL-bound values bypass updated().
        $tool = in_array($this->tool, $this->tools, true) ? $this->tool : '';
        $outcome = in_array($this->outcome, $this->outcomes, true) ? $this->outcome : '';
        $token = in_array($this->token, $this->tokenNames, true) ? $this->token : '';

        return AuditEntry::where('user_id', Auth::id())
            ->when($tool !== '', fn ($q) => $q->where('tool', $tool))
            ->when($outcome !== '', fn ($q) => $q->where('outcome', $outcome))
            ->when($token !== '', fn ($q) => $q->where('token_name', $token))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(50);
    }

    /** @return list<string> */
    private function distinct(string $column): array
    {
        return AuditEntry::where('user_id', Auth::id())->distinct()->orderBy($column)->pluck($column)->all();
    }
};
?>

<div class="mx-auto flex w-full max-w-6xl flex-col gap-6 p-6">
    <div>
        <flux:heading size="xl">{{ __('Audit log') }}</flux:heading>
        <flux:text class="mt-1">{{ __('Every MCP tool call. Entries older than :days days are pruned automatically.', ['days' => config('forge-mcp.audit_retention_days')]) }}</flux:text>
    </div>

    <div class="grid gap-3 sm:grid-cols-3">
        <flux:select wire:model.live="tool" :label="__('Tool')">
            <flux:select.option value="">{{ __('All tools') }}</flux:select.option>
            @foreach ($this->tools as $option)
                <flux:select.option :value="$option">{{ $option }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="outcome" :label="__('Outcome')">
            <flux:select.option value="">{{ __('All outcomes') }}</flux:select.option>
            @foreach ($this->outcomes as $option)
                <flux:select.option :value="$option">{{ $option }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="token" :label="__('Token')">
            <flux:select.option value="">{{ __('All tokens') }}</flux:select.option>
            @foreach ($this->tokenNames as $option)
                <flux:select.option :value="$option">{{ $option }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    <div class="flex flex-col divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
        @forelse ($this->entries as $entry)
            <details wire:key="entry-{{ $entry->id }}" class="p-3 text-sm" data-test="audit-row">
                <summary class="flex cursor-pointer flex-wrap items-center gap-x-3 gap-y-1">
                    <span class="w-40 shrink-0 text-zinc-500">{{ $entry->created_at?->format('Y-m-d H:i:s') }}</span>
                    <span class="font-mono">{{ $entry->tool }}</span>
                    <flux:badge size="sm" :color="match ($entry->outcome) { 'success' => 'green', 'denied' => 'amber', default => 'red' }">{{ $entry->outcome }}</flux:badge>
                    <span class="text-zinc-500">{{ $entry->token_name }}</span>
                    <span class="min-w-0 flex-1">{{ $entry->summary }}</span>
                    <span class="text-zinc-500">{{ $entry->duration_ms }} ms</span>
                </summary>
                <div class="mt-2 flex flex-col gap-2">
                    <flux:text size="sm">
                        {{ __('Organization: :org', ['org' => $entry->organization ?? '-']) }}
                        &middot; {{ __('Server: :id', ['id' => $entry->server_id ?? '-']) }}
                        &middot; {{ __('Site: :id', ['id' => $entry->site_id ?? '-']) }}
                    </flux:text>
                    <pre class="overflow-x-auto rounded-lg bg-zinc-100 p-3 text-xs dark:bg-zinc-800">{{ json_encode($entry->arguments, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) }}</pre>
                </div>
            </details>
        @empty
            <div class="p-6"><flux:text>{{ __('No audit entries match.') }}</flux:text></div>
        @endforelse
    </div>

    {{ $this->entries->links() }}
</div>

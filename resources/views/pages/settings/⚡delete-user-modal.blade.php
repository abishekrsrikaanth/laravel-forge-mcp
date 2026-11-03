<?php

use Livewire\Component;

/**
 * Account deletion is disabled: Forge MCP has a single owner, and deleting it would reopen /setup
 * to anyone who can reach the app. The profile page no longer offers it; this action refuses.
 */
new class extends Component {
    public string $password = '';

    public function deleteUser(): void
    {
        $this->addError('password', __('The owner account cannot be deleted: without it, setup would open to anyone who can reach this app.'));
    }
}; ?>

<flux:modal name="confirm-user-deletion" :show="$errors->isNotEmpty()" focusable class="max-w-lg">
    <div class="space-y-6">
        <flux:heading size="lg">{{ __('Account deletion is disabled') }}</flux:heading>

        <flux:subheading>
            {{ __('The owner account cannot be deleted: without it, setup would open to anyone who can reach this app.') }}
        </flux:subheading>

        <flux:error name="password" />

        <div class="flex justify-end">
            <flux:modal.close>
                <flux:button variant="filled">{{ __('Close') }}</flux:button>
            </flux:modal.close>
        </div>
    </div>
</flux:modal>

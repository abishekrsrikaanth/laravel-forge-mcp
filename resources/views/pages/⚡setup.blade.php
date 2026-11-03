<?php

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::auth'), Title('Set up Forge MCP')] class extends Component {
    use PasswordValidationRules, ProfileValidationRules;

    public string $name = '';
    public string $email = '';
    public string $password = '';
    public string $password_confirmation = '';

    /**
     * Setup is only available until the owner exists.
     */
    public function mount(): void
    {
        abort_if(User::ownerExists(), 404);
    }

    /**
     * Create the owner account and log them in.
     */
    public function createOwner(): void
    {
        abort_if(User::ownerExists(), 404);

        $validated = $this->validate([
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ]);

        $user = User::create($validated);
        $user->forceFill(['email_verified_at' => now()])->save();

        Auth::login($user);
        session()->regenerate();

        $this->redirect(route('dashboard', absolute: false), navigate: true);
    }
};
?>

<div class="flex flex-col gap-6">
    <x-auth-header :title="__('Set up Forge MCP')" :description="__('Create the owner account. This can only be done once.')" />

    <form wire:submit="createOwner" class="flex flex-col gap-6">
        <flux:input wire:model="name" :label="__('Name')" type="text" required autofocus autocomplete="name" :placeholder="__('Full name')" />

        <flux:input wire:model="email" :label="__('Email address')" type="email" required autocomplete="email" placeholder="email@example.com" />

        <flux:input
            wire:model="password"
            :label="__('Password')"
            type="password"
            required
            autocomplete="new-password"
            :placeholder="__('Password')"
            viewable
        />

        <flux:input
            wire:model="password_confirmation"
            :label="__('Confirm password')"
            type="password"
            required
            autocomplete="new-password"
            :placeholder="__('Confirm password')"
            viewable
        />

        <flux:button type="submit" variant="primary" class="w-full" data-test="setup-owner-button">
            {{ __('Create owner account') }}
        </flux:button>
    </form>
</div>

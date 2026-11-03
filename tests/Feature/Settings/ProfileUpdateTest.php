<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $this->actingAs($user = User::factory()->create());

        $this->get(route('profile.edit'))->assertOk();
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $response = Livewire::test('pages::settings.profile')
            ->set('name', 'Test User')
            ->set('email', 'test@example.com')
            ->call('updateProfileInformation');

        $response->assertHasNoErrors();

        $user->refresh();

        $this->assertEquals('Test User', $user->name);
        $this->assertEquals('test@example.com', $user->email);
    }

    public function test_email_verification_status_is_unchanged_when_email_address_is_unchanged(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $response = Livewire::test('pages::settings.profile')
            ->set('name', 'Test User')
            ->set('email', $user->email)
            ->call('updateProfileInformation');

        $response->assertHasNoErrors();

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_the_profile_page_does_not_offer_to_delete_the_owner_account(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('profile.edit'))
            ->assertOk()
            ->assertDontSee('Delete account')
            ->assertDontSee('data-test="delete-user-button"', false);
    }

    public function test_the_owner_account_cannot_be_deleted_because_setup_would_reopen(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        Livewire::test('pages::settings.delete-user-modal')
            ->set('password', 'password')
            ->call('deleteUser')
            ->assertHasErrors(['password'])
            ->assertNoRedirect();

        $this->assertNotNull($user->fresh());
        $this->assertTrue(auth()->check());
        $this->get('/setup')->assertNotFound();
    }

    public function test_changing_the_email_keeps_the_owner_verified(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        Livewire::test('pages::settings.profile')
            ->set('email', 'new-owner@example.com')
            ->call('updateProfileInformation')
            ->assertHasNoErrors();

        $user->refresh();
        $this->assertSame('new-owner@example.com', $user->email);
        $this->assertNotNull($user->email_verified_at);
        $this->get(route('dashboard'))->assertOk();
    }
}

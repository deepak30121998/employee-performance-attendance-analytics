<?php

namespace Modules\User\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\User\Enums\Role;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_login_with_valid_credentials(): void
    {
        $user = User::factory()->create(['password' => 'password123']);

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password123',
        ]);

        $response->assertOk()->assertJsonStructure(['user' => ['id', 'email', 'role'], 'token']);
    }

    public function test_login_fails_with_invalid_password(): void
    {
        $user = User::factory()->create(['password' => 'password123']);

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_login_fails_for_unknown_email(): void
    {
        $response = $this->postJson('/api/login', [
            'email' => 'nobody@example.com',
            'password' => 'whatever',
        ]);

        $response->assertStatus(422);
    }

    public function test_protected_route_rejects_missing_token(): void
    {
        $this->getJson('/api/profile')->assertStatus(401);
    }

    public function test_token_grants_access_to_profile(): void
    {
        $user = User::factory()->create(['role' => Role::Employee]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/profile')
            ->assertOk()
            ->assertJsonPath('id', $user->id);
    }

    public function test_logout_revokes_the_current_token(): void
    {
        $user = User::factory()->create(['password' => 'password123']);

        $login = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->json();

        [$tokenId] = explode('|', $login['token']);

        $this->withHeader('Authorization', 'Bearer '.$login['token'])
            ->postJson('/api/logout')
            ->assertOk();

        // a second request in the same process hits Sanctum's cached guard,
        // so assert on the token row itself
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId]);
    }
}

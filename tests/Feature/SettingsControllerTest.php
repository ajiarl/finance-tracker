<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SettingsControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_returns_current_user_preferences_and_supported_values(): void
    {
        $user = User::factory()->create([
            'locale' => 'id',
            'currency' => 'IDR',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/settings');

        $response->assertOk();
        $response->assertJsonPath('data.locale', 'id');
        $response->assertJsonPath('data.currency', 'IDR');
        $response->assertJsonPath('data.supported_locales.0', 'id');
        $response->assertJsonPath('data.supported_currencies.0', 'IDR');
    }

    public function test_update_allows_updating_one_or_more_supported_preferences(): void
    {
        $user = User::factory()->create([
            'locale' => 'id',
            'currency' => 'IDR',
        ]);

        Sanctum::actingAs($user);

        $response = $this->patchJson('/api/settings', [
            'locale' => 'en',
            'currency' => 'USD',
        ]);

        $response->assertOk();
        $response->assertJsonPath('message', 'Pengaturan berhasil disimpan.');
        $response->assertJsonPath('data.locale', 'en');
        $response->assertJsonPath('data.currency', 'USD');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'locale' => 'en',
            'currency' => 'USD',
        ]);
    }

    public function test_update_rejects_unsupported_values(): void
    {
        $user = User::factory()->create([
            'locale' => 'id',
            'currency' => 'IDR',
        ]);

        Sanctum::actingAs($user);

        $response = $this->patchJson('/api/settings', [
            'locale' => 'jp',
            'currency' => 'JPY',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['locale', 'currency']);
    }

    public function test_update_rejects_empty_payload(): void
    {
        $user = User::factory()->create([
            'locale' => 'id',
            'currency' => 'IDR',
        ]);

        Sanctum::actingAs($user);

        $response = $this->patchJson('/api/settings', []);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Tidak ada field yang valid untuk diupdate.');
        $response->assertJsonPath('allowed_fields.0', 'locale');
        $response->assertJsonPath('allowed_fields.1', 'currency');
    }
}

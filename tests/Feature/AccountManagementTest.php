<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccountManagementTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test export data endpoint.
     * GET /api/user/export-data
     */
    public function test_user_can_export_their_financial_data(): void
    {
        $user = User::factory()->create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/user/export-data');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/json');
        
        $data = $response->json();
        $this->assertEquals('John Doe', $data['profile']['name']);
        $this->assertEquals('john@example.com', $data['profile']['email']);
        $this->assertArrayHasKey('accounts', $data);
        $this->assertArrayHasKey('categories', $data);
        $this->assertArrayHasKey('budgets', $data);
    }

    /**
     * Test delete account endpoint with correct password.
     * DELETE /api/user/delete-account
     */
    public function test_user_can_delete_their_account_with_correct_password(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('secret123'),
        ]);

        Sanctum::actingAs($user);

        $response = $this->deleteJson('/api/user/delete-account', [
            'password' => 'secret123',
        ]);

        $response->assertOk();
        $response->assertJsonPath('message', 'Akun dan seluruh data berhasil dihapus secara permanen.');

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    /**
     * Test delete account endpoint with wrong password.
     */
    public function test_user_cannot_delete_their_account_with_wrong_password(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('secret123'),
        ]);

        Sanctum::actingAs($user);

        $response = $this->deleteJson('/api/user/delete-account', [
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('message', 'Password tidak sesuai.');

        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    /**
     * Test authentication requirement.
     */
    public function test_account_management_endpoints_require_authentication(): void
    {
        $this->getJson('/api/user/export-data')->assertStatus(401);
        $this->deleteJson('/api/user/delete-account', ['password' => 'secret'])->assertStatus(401);
    }
}

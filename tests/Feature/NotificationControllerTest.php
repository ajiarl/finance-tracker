<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_returns_only_authenticated_users_notifications_with_unread_count(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $readNotification = Notification::create([
            'user_id' => $user->id,
            'title' => 'Sudah dibaca',
            'message' => 'Pesan pertama',
            'type' => 'info',
            'read_at' => now(),
        ]);

        $unreadNotification = Notification::create([
            'user_id' => $user->id,
            'title' => 'Belum dibaca',
            'message' => 'Pesan kedua',
            'type' => 'warning',
            'read_at' => null,
        ]);

        Notification::create([
            'user_id' => $otherUser->id,
            'title' => 'User lain',
            'message' => 'Tidak boleh muncul',
            'type' => 'error',
            'read_at' => null,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/notifications');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('meta.total', 2);
        $response->assertJsonPath('meta.unread_count', 1);
        $response->assertJsonPath('data.0.id', $unreadNotification->id);
        $response->assertJsonPath('data.0.is_read', false);
        $response->assertJsonPath('data.1.id', $readNotification->id);
        $response->assertJsonPath('data.1.is_read', true);
    }

    public function test_mark_as_read_marks_single_notification_for_authenticated_user(): void
    {
        $user = User::factory()->create();

        $notification = Notification::create([
            'user_id' => $user->id,
            'title' => 'Notif',
            'message' => 'Pesan',
            'type' => 'info',
            'read_at' => null,
        ]);

        Sanctum::actingAs($user);

        $response = $this->patchJson("/api/notifications/{$notification->id}/read");

        $response->assertOk();
        $response->assertJsonPath('message', 'Notifikasi ditandai sudah dibaca.');
        $response->assertJsonPath('data.id', $notification->id);
        $response->assertJsonPath('data.is_read', true);

        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_mark_as_read_returns_not_found_for_other_users_notification(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $notification = Notification::create([
            'user_id' => $otherUser->id,
            'title' => 'Notif',
            'message' => 'Pesan',
            'type' => 'info',
            'read_at' => null,
        ]);

        Sanctum::actingAs($user);

        $response = $this->patchJson("/api/notifications/{$notification->id}/read");

        $response->assertNotFound();
        $response->assertJsonPath('message', 'Notifikasi tidak ditemukan.');
    }

    public function test_mark_all_as_read_updates_only_unread_notifications_for_authenticated_user(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        Notification::create([
            'user_id' => $user->id,
            'title' => 'Unread 1',
            'message' => 'Pesan 1',
            'type' => 'info',
            'read_at' => null,
        ]);

        Notification::create([
            'user_id' => $user->id,
            'title' => 'Unread 2',
            'message' => 'Pesan 2',
            'type' => 'warning',
            'read_at' => null,
        ]);

        Notification::create([
            'user_id' => $user->id,
            'title' => 'Already read',
            'message' => 'Pesan 3',
            'type' => 'success',
            'read_at' => now(),
        ]);

        Notification::create([
            'user_id' => $otherUser->id,
            'title' => 'User lain',
            'message' => 'Pesan 4',
            'type' => 'error',
            'read_at' => null,
        ]);

        Sanctum::actingAs($user);

        $response = $this->patchJson('/api/notifications/read-all');

        $response->assertOk();
        $response->assertJsonPath('message', 'Semua notifikasi ditandai sudah dibaca.');
        $response->assertJsonPath('updated_count', 2);

        $this->assertSame(0, Notification::forUser($user->id)->unread()->count());
        $this->assertSame(1, Notification::forUser($otherUser->id)->unread()->count());
    }
}

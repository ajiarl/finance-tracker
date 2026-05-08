<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        // Lightweight response for polling unread count
        if ($request->query('count_only')) {
            return response()->json([
                'unread_count' => Notification::forUser($userId)->unread()->count(),
            ]);
        }

        $notifications = Notification::forUser($userId)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn ($notification) => [
                'id' => $notification->id,
                'title' => $notification->title,
                'message' => $notification->message,
                'type' => $notification->type,
                'data' => $notification->data, // Metadata for deep-linking
                'is_read' => $notification->isRead(),
                'read_at' => $notification->read_at?->toISOString(),
                'created_at' => $notification->created_at->toISOString(),
            ]);

        return response()->json([
            'data' => $notifications,
            'meta' => [
                'total' => $notifications->count(),
                'unread_count' => $notifications->where('is_read', false)->count(),
            ],
        ]);
    }

    public function markAsRead(Request $request, int $id): JsonResponse
    {
        $notification = Notification::forUser($request->user()->id)->find($id);

        if (! $notification) {
            return response()->json(['message' => 'Notifikasi tidak ditemukan.'], 404);
        }

        $notification->markAsRead();

        return response()->json([
            'message' => 'Notifikasi ditandai sudah dibaca.',
            'data' => [
                'id' => $notification->id,
                'is_read' => true,
                'read_at' => $notification->read_at->toISOString(),
            ],
        ]);
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        $updated = Notification::forUser($request->user()->id)
            ->unread()
            ->update(['read_at' => now()]);

        return response()->json([
            'message' => 'Semua notifikasi ditandai sudah dibaca.',
            'updated_count' => $updated,
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $notification = Notification::forUser($request->user()->id)->find($id);

        if (! $notification) {
            return response()->json(['message' => 'Notifikasi tidak ditemukan.'], 404);
        }

        $notification->delete();

        return response()->json([
            'message' => 'Notifikasi berhasil dihapus.',
        ]);
    }
}

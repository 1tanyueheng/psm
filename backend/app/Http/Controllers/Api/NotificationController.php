<?php

namespace App\Http\Controllers\Api;

use App\Enums\NotificationType;
use App\Http\Controllers\ApiController;
use App\Http\Resources\NotificationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Module 6 — In-app notification centre and preferences.
 */
class NotificationController extends ApiController
{
    /**
     * GET /api/notifications
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = $user->receivedNotifications()
            ->when($request->boolean('unread_only'), fn ($q) => $q->whereNull('read_at'))
            ->when($request->filled('type'), fn ($q) => $q->where('data->type_key', $request->input('type')));

        $paginator = $query->paginate($request->integer('per_page', 20));

        return $this->paginated($paginator, fn ($n) => (new NotificationResource($n))->resolve($request));
    }

    /**
     * GET /api/notifications/summary
     *
     * The badge count and the latest few, so the header can poll one endpoint.
     */
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();

        return $this->ok([
            'unread_count' => $user->receivedNotifications()->whereNull('read_at')->count(),
            'latest'       => NotificationResource::collection(
                $user->receivedNotifications()->limit(5)->get()
            ),
        ]);
    }

    /**
     * POST /api/notifications/{id}/read
     */
    public function markRead(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()
            ->receivedNotifications()
            ->where('id', $id)
            ->firstOrFail();

        $notification->markAsRead();

        return $this->ok([
            'unread_count' => $request->user()->receivedNotifications()->whereNull('read_at')->count(),
        ], 'Marked as read.');
    }

    /**
     * POST /api/notifications/read-all
     */
    public function markAllRead(Request $request): JsonResponse
    {
        $count = $request->user()
            ->receivedNotifications()
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return $this->ok(['marked' => $count], 'All notifications marked as read.');
    }

    /**
     * DELETE /api/notifications/{id}
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $request->user()
            ->receivedNotifications()
            ->where('id', $id)
            ->delete();

        return $this->ok(null, 'Notification dismissed.');
    }

    /**
     * GET /api/notifications/preferences
     *
     * The delivery settings a user can change. There is now only one: digest
     * mode, which decides **when** a notification arrives, never whether.
     *
     * The per-type switch list was removed on 2026-11-01 at the user's
     * direction. A PSM notification reports a decision on the student's own
     * project, so there is nothing sensible for a student to switch off; the
     * catalogue is returned read-only so the screen can still tell a user what
     * the system will send them and through which channels.
     */
    public function preferences(Request $request): JsonResponse
    {
        $user = $request->user();

        return $this->ok([
            'digest_only' => (bool) $user->digest_only,
            'groups'      => collect(NotificationType::grouped())
                ->map(fn (array $types, string $group) => [
                    'group' => $group,
                    'types' => collect($types)->map(function (string $value) use ($user) {
                        $type = NotificationType::from($value);

                        return [
                            'value'    => $value,
                            'label'    => $type->label(),
                            // Always true, and reported as such rather than
                            // omitted: a user reading this screen should be able
                            // to see that the type will reach them.
                            'enabled'  => true,
                            'urgent'   => $type->isUrgent(),
                            'channels' => $user->channelsFor($type),
                        ];
                    })->values(),
                ])
                ->values(),
        ]);
    }

    /**
     * PUT /api/notifications/preferences
     *
     * Only digest mode is accepted. A `preferences` array is still tolerated and
     * ignored, so an older client is not broken by a 422 it cannot act on — but
     * it no longer changes anything, because no type can be switched off.
     */
    public function updatePreferences(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'digest_only' => ['sometimes', 'boolean'],
        ]);

        $user = $request->user();

        if (array_key_exists('digest_only', $validated)) {
            $user->digest_only = $validated['digest_only'];
            $user->save();
        }

        return $this->ok(null, 'Notification preferences saved.');
    }
}

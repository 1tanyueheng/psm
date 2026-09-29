<?php

namespace App\Http\Controllers\Api;

use App\Enums\NotificationType;
use App\Http\Controllers\ApiController;
use App\Http\Resources\NotificationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
                            // Urgent types cannot be disabled, so the UI greys them out
                            'enabled'  => $user->wantsNotification($type),
                            'lockable' => $type->isUrgent(),
                            'channels' => $type->defaultChannels(),
                        ];
                    })->values(),
                ])
                ->values(),
        ]);
    }

    /**
     * PUT /api/notifications/preferences
     */
    public function updatePreferences(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'digest_only'      => ['sometimes', 'boolean'],
            'preferences'      => ['sometimes', 'array'],
            'preferences.*.type'    => ['required', Rule::in(NotificationType::values())],
            'preferences.*.enabled' => ['required', 'boolean'],
        ]);

        $user = $request->user();

        if (array_key_exists('digest_only', $validated)) {
            $user->digest_only = $validated['digest_only'];
        }

        foreach ($validated['preferences'] ?? [] as $pref) {
            $type = NotificationType::from($pref['type']);

            // Urgent types are forced on; silently ignore an attempt to disable
            if ($type->isUrgent()) {
                continue;
            }

            $user->setNotificationPreference($type, (bool) $pref['enabled']);
        }

        $user->save();

        return $this->ok(null, 'Notification preferences saved.');
    }
}

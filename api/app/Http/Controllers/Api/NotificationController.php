<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NotificationSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 알림센터 · 알림 설정 · 푸시 구독
 */
class NotificationController extends Controller
{
    /** GET /api/notifications?page=2 — 최신 30개씩 */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $page = $user->notifications()->latest()->simplePaginate(30);

        return response()->json([
            'data' => collect($page->items())->map(fn ($n) => [
                'id' => $n->id,
                ...$n->data,                       // kind, title, body, url, actor
                'read' => $n->read_at !== null,
                'created_at' => $n->created_at->toIso8601String(),
            ]),
            'has_more' => $page->hasMorePages(),
            'unread' => $user->unreadNotifications()->count(),
        ]);
    }

    /** POST /api/notifications/{id}/read */
    public function read(Request $request, string $id): JsonResponse
    {
        $request->user()->unreadNotifications()->whereKey($id)->update(['read_at' => now()]);

        return response()->json(['unread' => $request->user()->unreadNotifications()->count()]);
    }

    /** POST /api/notifications/read-all */
    public function readAll(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['unread' => 0]);
    }

    /** GET /api/me/notification-settings */
    public function settings(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->settingsPayload($request)]);
    }

    /** PUT /api/me/notification-settings  {friend?, chat?, meetup?, night_quiet?} */
    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate(collect([...NotificationSetting::GROUPS, 'night_quiet'])
            ->mapWithKeys(fn ($k) => [$k => ['sometimes', 'boolean']])->all());

        NotificationSetting::updateOrCreate(['user_id' => $request->user()->id], $data);

        return response()->json(['data' => $this->settingsPayload($request)]);
    }

    /**
     * POST /api/push/subscriptions  {endpoint, keys:{p256dh, auth}, content_encoding?}
     * 브라우저가 준 구독 정보를 기기별로 저장 (같은 endpoint면 갱신)
     */
    public function subscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'url', 'starts_with:https://', 'max:500'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'content_encoding' => ['nullable', 'in:aesgcm,aes128gcm'],
        ]);

        $request->user()->updatePushSubscription(
            $data['endpoint'], $data['keys']['p256dh'], $data['keys']['auth'], $data['content_encoding'] ?? 'aes128gcm',
        );

        return response()->json(['status' => 'ok'], 201);
    }

    /** DELETE /api/push/subscriptions  {endpoint} — 이 기기 푸시 끄기 */
    public function unsubscribe(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:500']]);
        $request->user()->deletePushSubscription($data['endpoint']);

        return response()->json(['status' => 'ok']);
    }

    private function settingsPayload(Request $request): array
    {
        $user = $request->user()->fresh();
        $s = $user->notificationSettingOrDefault();

        return [
            'friend' => $s->friend,
            'chat' => $s->chat,
            'meetup' => $s->meetup,
            'night_quiet' => $s->night_quiet,
            'push_devices' => $user->pushSubscriptions()->count(), // 푸시를 켠 기기 수
        ];
    }
}

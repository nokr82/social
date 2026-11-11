<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ChatMessageResource;
use App\Models\Plaza;
use App\Services\Town\TownService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/** 동네 상시 광장 (메인 상단 카드 + 구역·채널 화면) */
class TownController extends Controller
{
    public function __construct(private readonly TownService $town) {}

    /**
     * GET /api/town?lat&lng — 메인 상단 '우리 동네 상시 광장' 카드.
     * 좌표는 동네 찾기에만 쓰고 저장하지 않는다. 동네를 못 찾으면 data: null (카드 숨김)
     */
    public function mine(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);
        $found = $this->town->forLocation((float) $data['lat'], (float) $data['lng']);
        if (! $found) {
            return response()->json(['data' => null]);
        }
        [$plaza, $region] = $found;

        return response()->json(['data' => $this->town->summary($request->user(), $plaza) + ['region_name' => $region->name]]);
    }

    /** GET /api/plazas/{plaza}/town — 구역 지도·채널 현황 */
    public function show(Plaza $plaza): JsonResponse
    {
        return response()->json(['data' => $this->town->state($plaza)]);
    }

    /** POST /api/plazas/{plaza}/town/enter {zone?, channel?} — 입장·구역 이동·채널 이동 */
    public function enter(Request $request, Plaza $plaza): JsonResponse
    {
        $data = $request->validate([
            'zone' => ['nullable', 'string', Rule::in(array_keys(config('social.town.zones')))],
            'channel' => ['nullable', 'integer', 'min:1', 'max:'.config('social.town.max_channels')],
        ]);
        $at = $this->town->enter($request->user(), $plaza, $data['zone'] ?? null, isset($data['channel']) ? (int) $data['channel'] : null);

        return response()->json($at + [
            'presence_channel' => "town.{$plaza->id}.{$at['zone']}.{$at['channel']}",
            'heartbeat_interval' => (int) config('social.plaza.presence_ttl') / 2,
            'state' => $this->town->state($plaza), // 이동 직후 지도 숫자 갱신용
        ]);
    }

    /** POST /api/plazas/{plaza}/town/heartbeat {zone, channel} — 화면에 있는 동안 30초마다 */
    public function heartbeat(Request $request, Plaza $plaza): JsonResponse
    {
        [$zone, $channel] = $this->place($request);
        $this->town->heartbeat($request->user(), $plaza, $zone, $channel);

        return response()->json(['status' => 'ok']);
    }

    /** POST /api/plazas/{plaza}/town/leave {zone, channel} */
    public function leave(Request $request, Plaza $plaza): JsonResponse
    {
        [$zone, $channel] = $this->place($request);
        $this->town->leave($request->user(), $plaza, $zone, $channel);

        return response()->json(['status' => 'ok']);
    }

    /** GET /api/plazas/{plaza}/town/messages?zone&channel&before_id */
    public function messages(Request $request, Plaza $plaza): AnonymousResourceCollection
    {
        [$zone, $channel] = $this->place($request);
        $beforeId = $request->validate(['before_id' => ['nullable', 'integer', 'min:1']])['before_id'] ?? null;

        return ChatMessageResource::collection(
            $this->town->messages($request->user(), $plaza, $zone, $channel, $beforeId ? (int) $beforeId : null)
        );
    }

    /** POST /api/plazas/{plaza}/town/messages {zone, channel, body} */
    public function send(Request $request, Plaza $plaza): JsonResponse
    {
        [$zone, $channel] = $this->place($request);
        $body = trim($request->validate(['body' => ['required', 'string', 'max:500']])['body']);
        abort_if($body === '', 422, '빈 메시지는 보낼 수 없어요');

        $message = $this->town->sendMessage($request->user(), $plaza, $zone, $channel, $body);

        return (new ChatMessageResource($message->load('sender.avatar')))->response()->setStatusCode(201);
    }

    /** @return array{0: string, 1: int} 요청의 구역·채널 */
    private function place(Request $request): array
    {
        $data = $request->validate([
            'zone' => ['required', 'string', Rule::in(array_keys(config('social.town.zones')))],
            'channel' => ['required', 'integer', 'min:1', 'max:'.config('social.town.max_channels')],
        ]);

        return [$data['zone'], (int) $data['channel']];
    }
}

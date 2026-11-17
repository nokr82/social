<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PlazaResource;
use App\Http\Resources\AvatarSummary;
use App\Models\Plaza;
use App\Models\PlazaKick;
use App\Models\User;
use App\Services\Plaza\PlazaService;
use App\Support\Geo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PlazaController extends Controller
{
    public function __construct(private readonly PlazaService $plazas) {}

    /**
     * GET /api/plazas?lat&lng&category&q&sort&page
     * 메인 화면 한 번에: hot(지금 제일 북적여요) + data(내 주변 광장, 가까운 순)
     * 좌표는 거리 계산에만 쓰고 저장하지 않는다.
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            // 위치 권한이 없으면 좌표 없이 검색만 가능 (주변 목록은 빈 목록)
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
            'category' => ['nullable', Rule::in(array_keys(config('social.categories')))],
            'q' => ['nullable', 'string', 'max:30'],
            'sort' => ['nullable', Rule::in(['near', 'busy', 'new'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $all = $this->plazas->nearby(
            $request->user(),
            isset($data['lat']) ? (float) $data['lat'] : null,
            isset($data['lng']) ? (float) $data['lng'] : null,
            $data['category'] ?? null, isset($data['q']) ? trim($data['q']) : null,
            $data['sort'] ?? 'near',
        );

        $perPage = 20;
        $page = (int) ($data['page'] ?? 1);
        $hot = $page === 1 ? $this->plazas->hottest($all) : null;

        return response()->json([
            'hot' => $hot ? new PlazaResource($hot) : null,
            'data' => PlazaResource::collection($all->forPage($page, $perPage)->values()),
            'meta' => ['page' => $page, 'has_more' => $all->count() > $page * $perPage],
        ]);
    }

    /** POST /api/plazas  {name, category, description?, capacity?, lat, lng} */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:40'],
            'description' => ['nullable', 'string', 'max:200'],
            'category' => ['required', Rule::in(array_keys(config('social.categories')))],
            'capacity' => ['nullable', 'integer', 'min:2', 'max:'.config('social.plaza.max_capacity')],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $plaza = $this->plazas->create($request->user(), $data, (float) $data['lat'], (float) $data['lng']);
        // 만든 사람은 바로 입장 (방장이라 거리 검사 없음)
        $this->plazas->enter($request->user(), $plaza);

        return (new PlazaResource($this->plazas->attachLive(collect([$plaza]))->first()))
            ->response()->setStatusCode(201);
    }

    /** GET /api/plazas/{plaza}?lat&lng — 좌표를 주면 distance_label 포함 */
    public function show(Request $request, Plaza $plaza): PlazaResource
    {
        $data = $request->validate([
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
        ]);
        if (isset($data['lat'])) {
            $plaza->distance_m = Geo::distanceM((float) $data['lat'], (float) $data['lng'], $plaza->lat, $plaza->lng);
            $plaza->can_enter = $plaza->distance_m <= (float) config('social.plaza.search_radius_m');
        }

        return new PlazaResource($this->plazas->attachLive(collect([$plaza]))->first());
    }

    /** POST /api/plazas/{plaza}/enter {lat?, lng?} — 처음 들어올 땐 좌표 필수(5km 안), 이미 안에 있거나 방장이면 생략 가능 */
    public function enter(Request $request, Plaza $plaza): JsonResponse
    {
        $data = $request->validate([
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
        ]);
        $this->plazas->enter(
            $request->user(), $plaza,
            isset($data['lat']) ? (float) $data['lat'] : null,
            isset($data['lng']) ? (float) $data['lng'] : null,
        );

        return response()->json([
            'channel' => "presence-plaza.{$plaza->id}",
            'heartbeat_interval' => (int) config('social.plaza.presence_ttl') / 2,
        ]);
    }

    /** POST /api/plazas/{plaza}/quit — '나가기' 버튼: 참여를 끝냄. 방장이면 바로 위임(없으면 닫힘) */
    public function quit(Request $request, Plaza $plaza): JsonResponse
    {
        $this->plazas->quit($request->user(), $plaza);

        return response()->json(['status' => 'ok']);
    }

    /** POST /api/plazas/{plaza}/read {last_id} — 읽음 위치 (앞으로만) */
    public function read(Request $request, Plaza $plaza): JsonResponse
    {
        $data = $request->validate(['last_id' => ['required', 'integer', 'min:1']]);
        $this->plazas->markRead($request->user(), $plaza, (int) $data['last_id']);

        return response()->json(['status' => 'ok']);
    }

    /**
     * GET /api/me/plazas — 채팅 탭 '광장': 내가 참여 중인 광장, 최근 대화 순
     * {data[{plaza, member_count, unread, last_message{body, sender, mine, created_at}|null}], unread_total}
     */
    public function joined(Request $request): JsonResponse
    {
        $me = $request->user();
        $rows = $this->plazas->joined($me);

        return response()->json([
            'data' => $rows->map(fn (array $r) => [
                'plaza' => new PlazaResource($r['plaza']),
                'member_count' => $r['plaza']->member_count,
                'unread' => $r['unread'],
                'last_message' => $r['last_message'] ? [
                    'body' => $r['last_message']->preview(),
                    'sender' => $r['last_message']->sender?->nickname,
                    'mine' => (int) $r['last_message']->sender_id === (int) $me->id,
                    'created_at' => $r['last_message']->created_at?->toIso8601String(),
                ] : null,
            ])->all(),
            'unread_total' => $rows->sum('unread'),
        ]);
    }

    /** POST /api/plazas/{plaza}/heartbeat — 광장 화면에 있는 동안 30초마다 */
    public function heartbeat(Request $request, Plaza $plaza): JsonResponse
    {
        $this->plazas->heartbeat($request->user(), $plaza);

        return response()->json(['status' => 'ok']);
    }

    /** POST /api/plazas/{plaza}/leave */
    public function leave(Request $request, Plaza $plaza): JsonResponse
    {
        $this->plazas->leave($request->user(), $plaza);

        return response()->json(['status' => 'ok']);
    }

    /* ---------------- 방장 권한 ---------------- */

    /** POST /api/plazas/{plaza}/kick  {user_id, reason} — 방장이 해제할 때까지 재입장 불가 */
    public function kick(Request $request, Plaza $plaza): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'reason' => ['required', Rule::in(array_keys(config('social.plaza.kick_reasons')))],
        ]);
        $kick = $this->plazas->kick($request->user(), $plaza, User::findOrFail($data['user_id']), $data['reason']);

        return response()->json(['status' => 'ok', 'kick_id' => $kick->id]);
    }

    /** GET /api/plazas/{plaza}/kicks — 강퇴 목록 (방장만) */
    public function kicks(Request $request, Plaza $plaza): JsonResponse
    {
        $reasons = config('social.plaza.kick_reasons');
        $data = $this->plazas->kicks($request->user(), $plaza)->map(fn (PlazaKick $k) => [
            'id' => $k->id,
            'user' => AvatarSummary::of($k->user),
            'reason_label' => $reasons[$k->reason] ?? $k->reason,
            'kicked_at' => $k->created_at?->toIso8601String(),
        ]);

        return response()->json(['data' => $data]);
    }

    /** DELETE /api/plazas/{plaza}/kicks/{kick} — 강퇴 해제 (방장만) */
    public function revokeKick(Request $request, Plaza $plaza, PlazaKick $kick): JsonResponse
    {
        $this->plazas->revokeKick($request->user(), $plaza, $kick);

        return response()->json(['status' => 'ok']);
    }

    /** POST /api/plazas/{plaza}/transfer  {user_id} — 방장 넘기기 */
    public function transfer(Request $request, Plaza $plaza): JsonResponse
    {
        $data = $request->validate(['user_id' => ['required', 'integer', 'exists:users,id']]);
        $this->plazas->transferHost($request->user(), $plaza, User::findOrFail($data['user_id']));

        return response()->json(['status' => 'ok']);
    }

    /** POST /api/plazas/{plaza}/close — 방장이 광장 닫기 */
    public function close(Request $request, Plaza $plaza): JsonResponse
    {
        $this->plazas->closeByHost($request->user(), $plaza);

        return response()->json(['status' => 'ok']);
    }
}

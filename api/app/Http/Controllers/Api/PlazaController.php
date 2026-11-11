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
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'category' => ['nullable', Rule::in(array_keys(config('social.categories')))],
            'q' => ['nullable', 'string', 'max:30'],
            'sort' => ['nullable', Rule::in(['near', 'busy', 'new'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $all = $this->plazas->nearby(
            $request->user(), (float) $data['lat'], (float) $data['lng'],
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
        // 만든 사람은 바로 입장
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
        }

        return new PlazaResource($this->plazas->attachLive(collect([$plaza]))->first());
    }

    /** POST /api/plazas/{plaza}/enter */
    public function enter(Request $request, Plaza $plaza): JsonResponse
    {
        $this->plazas->enter($request->user(), $plaza);

        return response()->json([
            'channel' => "presence-plaza.{$plaza->id}",
            'heartbeat_interval' => (int) config('social.plaza.presence_ttl') / 2,
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

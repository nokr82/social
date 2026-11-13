<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AvatarSummary;
use App\Models\LocationShare;
use App\Models\User;
use App\Services\Location\LocationShareService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** 친구 위치 공유 (지도) */
class LocationController extends Controller
{
    public function __construct(private readonly LocationShareService $shares) {}

    /** GET /api/location/shares — 내가 위치를 공유 중인 친구 (설정 시트·'공유 중' 표시) */
    public function shares(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->outgoing($request->user())]);
    }

    /** PUT /api/location/shares/{user} {duration: 1h|today|always} — 공유 켜기·기간 변경 */
    public function share(Request $request, User $user): JsonResponse
    {
        $data = $request->validate(['duration' => ['required', Rule::in(LocationShareService::DURATIONS)]]);
        $this->shares->share($request->user(), $user->id, $data['duration']);

        return response()->json(['data' => $this->outgoing($request->user())]);
    }

    /** DELETE /api/location/shares/{user} — 그 친구에게 공유 끄기 */
    public function stop(Request $request, User $user): JsonResponse
    {
        $this->shares->stop($request->user(), $user->id);

        return response()->json(['data' => $this->outgoing($request->user())]);
    }

    /** DELETE /api/location/shares — 모두 끄기 */
    public function stopAll(Request $request): JsonResponse
    {
        $this->shares->stopAll($request->user());

        return response()->json(['data' => []]);
    }

    /** POST /api/location {lat, lng, accuracy?} — 내 위치 갱신 (공유 중일 때만 저장·전송) */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ]);

        return response()->json($this->shares->update(
            $request->user(), (float) $data['lat'], (float) $data['lng'],
            isset($data['accuracy']) ? (float) $data['accuracy'] : null,
        ));
    }

    /** GET /api/location/friends — 나에게 공유 중인 친구와 마지막 위치 (지도) */
    public function friends(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->shares->friendsFor($request->user())]);
    }

    private function outgoing(User $me): array
    {
        return $this->shares->outgoing($me)->map(fn (LocationShare $s) => [
            'user' => AvatarSummary::of($s->viewer),
            'until' => $s->expires_at?->toIso8601String(), // null = 끌 때까지
        ])->values()->all();
    }
}

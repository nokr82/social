<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MeResource;
use App\Support\Geo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MeController extends Controller
{
    public function show(Request $request): MeResource
    {
        return new MeResource($request->user()->load('identity', 'profile', 'avatar'));
    }

    /** PATCH /api/me/profile  {bio?, interests?} — 성별·나이는 이 API로 바꿀 수 없음 */
    public function updateProfile(Request $request): MeResource
    {
        $data = $request->validate([
            'bio' => ['sometimes', 'nullable', 'string', 'max:300'],
            'interests' => ['sometimes', 'array', 'max:10'],
            'interests.*' => ['string', 'max:20'],
        ]);

        $request->user()->profile()->updateOrCreate([], $data);

        return $this->show($request);
    }

    /**
     * PUT /api/me/location  {lat, lng}
     * geohash 6자리(약 1km 칸)만 저장하고 좌표 원본은 버린다.
     */
    public function updateLocation(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $request->user()->forceFill([
            'region_cell' => Geo::encode((float) $data['lat'], (float) $data['lng'], 6),
            'last_seen_at' => now(),
        ])->save();

        return response()->json(['status' => 'ok']);
    }

    /** PUT /api/me/avatar  {parts:{color, face}} — 선택지는 config/social.php avatar */
    public function updateAvatar(Request $request): MeResource
    {
        $data = $request->validate([
            'parts' => ['required', 'array:color,face'],
            'parts.color' => ['required', Rule::in(config('social.avatar.colors'))],
            'parts.face' => ['required', Rule::in(config('social.avatar.faces'))],
        ]);

        $avatar = $request->user()->avatar()->firstOrNew();
        $avatar->parts = $data['parts'];
        $avatar->version = ($avatar->version ?? 0) + 1; // 앱 캐시 무효화용
        $avatar->save();

        return $this->show($request);
    }
}

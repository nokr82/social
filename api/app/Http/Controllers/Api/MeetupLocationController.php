<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Meetup;
use App\Services\Meetup\MeetupLocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** 번개 '모이는 중' 지도 (목적지 + 위치 공유를 켠 참여자) */
class MeetupLocationController extends Controller
{
    public function __construct(private readonly MeetupLocationService $service) {}

    /** GET /api/meetups/{meetup}/locations — 지도 데이터 (참여 확정자만) */
    public function board(Request $request, Meetup $meetup): JsonResponse
    {
        return response()->json(['data' => $this->service->board($request->user(), $meetup)]);
    }

    /** PUT /api/meetups/{meetup}/location-share — 내 위치 공유 켜기 */
    public function start(Request $request, Meetup $meetup): JsonResponse
    {
        $this->service->start($request->user(), $meetup);

        return response()->json(['data' => $this->service->board($request->user(), $meetup)]);
    }

    /** DELETE /api/meetups/{meetup}/location-share — 끄기 */
    public function stop(Request $request, Meetup $meetup): JsonResponse
    {
        $this->service->stop($request->user(), $meetup);

        return response()->json(['data' => $this->service->board($request->user(), $meetup)]);
    }

    /** GET /api/me/meetup-location-shares — 내가 켜 둔 번개 (위치를 계속 보낼지·'공유 중' 띠) */
    public function mine(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->service->mine($request->user())]);
    }
}

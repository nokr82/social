<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Meetup;
use App\Models\Plaza;
use App\Services\Share\ShareService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** 공유 링크 만들기 → {url, title, text}. 앱은 휴대폰 공유창(없으면 링크 복사)으로 보낸다 */
class ShareController extends Controller
{
    public function __construct(private readonly ShareService $share) {}

    /** POST /api/plazas/{plaza}/share — 참여자·방장만 */
    public function plaza(Request $request, Plaza $plaza): JsonResponse
    {
        return response()->json(['data' => $this->share->plazaLink($request->user(), $plaza)]);
    }

    /** POST /api/meetups/{meetup}/share — 시작 전 번개, 호스트와 차단 관계가 아니면 */
    public function meetup(Request $request, Meetup $meetup): JsonResponse
    {
        return response()->json(['data' => $this->share->meetupLink($request->user(), $meetup)]);
    }
}

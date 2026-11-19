<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Meetup;
use App\Services\Meetup\MeetupInviteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** 번개 친구 초대 */
class MeetupInviteController extends Controller
{
    public function __construct(private readonly MeetupInviteService $invites) {}

    /** GET /api/meetups/{meetup}/invites — 초대 시트 (친구 + 상태) */
    public function index(Request $request, Meetup $meetup): JsonResponse
    {
        return response()->json(['data' => $this->invites->candidates($request->user(), $meetup)]);
    }

    /** POST /api/meetups/{meetup}/invites {user_ids: [..]} — 고른 친구들에게 초대 카드 */
    public function store(Request $request, Meetup $meetup): JsonResponse
    {
        $data = $request->validate([
            'user_ids' => ['required', 'array', 'min:1', 'max:'.MeetupInviteService::INVITE_LIMIT],
            'user_ids.*' => ['integer'],
        ]);
        $result = $this->invites->invite($request->user(), $meetup, $data['user_ids']);

        return response()->json(['data' => $result + $this->invites->candidates($request->user(), $meetup)]);
    }
}

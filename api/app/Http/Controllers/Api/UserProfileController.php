<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Resources\AvatarSummary;
use App\Models\ProfilePhoto;
use App\Models\User;
use App\Services\Friend\FriendService;
use App\Services\Photo\PhotoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserProfileController extends Controller
{
    /**
     * GET /api/users/{user} — 상대 프로필 (디자인: Profile.dc.html, 광장 미니 카드)
     * 친구가 아니면 공개 사진만, 친구면 비공개 사진·성별·나이까지.
     */
    public function show(Request $request, User $user, PhotoService $photos, FriendService $friends): JsonResponse
    {
        $me = $request->user();
        if ($me->isBlockedWith($user->id) || ! $user->isActive()) {
            throw new DomainException('NOT_FOUND', '사용자를 찾을 수 없어요', 404); // 차단 사실을 드러내지 않음
        }

        $relation = $user->id === $me->id ? 'self' : $friends->status($me, $user->id);
        $canSeePrivate = in_array($relation, ['self', 'friends'], true);

        $all = $user->photos()->get();
        $public = $all->where('visibility', ProfilePhoto::PUBLIC);
        $private = $all->where('visibility', ProfilePhoto::PRIVATE);
        $user->loadMissing('profile', 'avatar', 'identity');

        return response()->json(['data' => AvatarSummary::of($user) + [
            'bio' => $user->profile?->bio,
            'interests' => $user->profile?->interests ?? [],
            'relation' => $relation,                           // self | none | sent | received | friends
            'public_hearts' => (int) $public->sum('like_count'), // 디자인: 공개 사진 하트 합계
            'photos' => $photos->present($public, $me),
            'private_count' => $private->count(),
            // 친구에게만 공개되는 값 (본인인증 기준이라 수정 불가)
            'private_photos' => $canSeePrivate ? $photos->present($private, $me) : null,
            'gender' => $canSeePrivate ? $user->identity?->gender : null,
            'age' => $canSeePrivate ? $user->identity?->age() : null,
        ]]);
    }
}

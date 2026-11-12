<?php

namespace App\Http\Resources;

use App\Models\FriendRequest;
use App\Models\Friendship;
use App\Models\ProfilePhoto;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** 본인 정보. 본인에게만 성별·나이를 보여준다. 매너점수 수치는 비공개 */
class MeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nickname' => $this->nickname,
            'status' => $this->status,
            'suspended_until' => $this->suspended_until?->toIso8601String(),
            'gender' => $this->identity?->gender,
            'age' => $this->identity?->age(),
            'identity_verified' => (bool) $this->identity?->isVerified(),
            'bio' => $this->profile?->bio,
            'interests' => $this->profile?->interests ?? [],
            'avatar' => $this->avatar?->parts,
            'avatar_version' => $this->avatar?->version,
            'has_location' => $this->region_cell !== null,
            // MY 상단 숫자 (디자인: 받은 하트 · 친구 · 받은 친구 요청)
            'stats' => [
                'hearts' => (int) ProfilePhoto::where('user_id', $this->id)->sum('like_count'), // 비공개 사진 하트 포함
                'friends' => Friendship::where('user_id', $this->id)->count(),
                'received_requests' => FriendRequest::alive()->where('to_id', $this->id)->where('status', FriendRequest::PENDING)->count(),
                'photos' => ProfilePhoto::where('user_id', $this->id)->count(),
                'unread_notifications' => $this->unreadNotifications()->count(), // 종 아이콘 숫자
            ],
        ];
    }
}

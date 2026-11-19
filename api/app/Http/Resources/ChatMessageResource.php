<?php

namespace App\Http\Resources;

use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Services\Location\LocationShareService;
use App\Services\Chat\ChatAttachmentService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChatMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $attachments = app(ChatAttachmentService::class);

        return [
            'id' => $this->id,
            'type' => $this->type,
            'body' => $this->body,
            'sender' => AvatarSummary::of($this->sender),
            'created_at' => $this->created_at?->toIso8601String(),
            // 사진: {url(30분), width, height} — 크기를 미리 알려 이미지 로딩 중 화면이 덜컹거리지 않게
            'image' => $this->when($this->type === ChatMessage::IMAGE, fn () => [
                'url' => $attachments->imageUrl($this->resource),
                'width' => $this->meta['w'] ?? null,
                'height' => $this->meta['h'] ?? null,
            ]),
            // 번개 카드: 최신 상태 (지워졌으면 null → '사라진 번개')
            'meetup' => $this->when($this->type === ChatMessage::MEETUP, fn () => $attachments->meetupSummary($this->resource)),
            // 번개 초대 카드인지 (같은 번개 카드지만 '○○님이 초대했어요'로 보여줌)
            'invite' => $this->when($this->type === ChatMessage::MEETUP, fn () => (bool) ($this->meta['invite'] ?? false)),
            // 위치 카드: 보낸 사람 → 대화 상대 공유가 지금도 유효한지 (좌표는 GET /api/location/friends로 따로 받음)
            'location' => $this->when($this->type === ChatMessage::LOCATION, fn () => $this->locationCard()),
        ];
    }

    /** @return array{sharer_id:int, active:bool, until:?string} */
    private function locationCard(): array
    {
        $room = ChatRoom::find($this->room_id);
        $share = $room?->type === ChatRoom::TYPE_DM
            ? app(LocationShareService::class)->activeShare($this->sender_id, $room->otherMemberId($this->sender_id))
            : null;

        return [
            'sharer_id' => $this->sender_id,
            'active' => (bool) $share,
            'until' => $share ? $share->expires_at?->toIso8601String() : ($this->meta['until'] ?? null),
        ];
    }
}

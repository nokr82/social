<?php

namespace App\Http\Resources;

use App\Models\ChatMessage;
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
        ];
    }
}

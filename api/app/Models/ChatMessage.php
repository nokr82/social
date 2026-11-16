<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatMessage extends Model
{
    public const UPDATED_AT = null;

    public const TEXT = 'text';
    public const SYSTEM = 'system';
    public const IMAGE = 'image';     // meta {path, w, h}
    public const MEETUP = 'meetup';   // meta {meetup_id}
    public const LOCATION = 'location'; // meta {until} — DM에서 위치 공유를 켰다는 카드 (좌표는 저장하지 않음)

    // DB 기본값과 동일 (create() 직후 응답에 type이 null로 나가지 않도록)
    protected $attributes = ['type' => self::TEXT];

    protected $fillable = ['room_id', 'channel', 'sender_id', 'type', 'body', 'meta']; // channel: 상시 광장 "구역:채널"

    protected function casts(): array
    {
        return ['meta' => 'array'];
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    /** 채팅 목록·푸시에 쓰는 한 줄 요약 */
    public function preview(): string
    {
        return match ($this->type) {
            self::IMAGE => '사진을 보냈어요',
            self::MEETUP => "번개를 공유했어요: {$this->body}",
            self::LOCATION => '📍 위치를 공유했어요',
            default => $this->body,
        };
    }
}

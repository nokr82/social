<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatRoom extends Model
{
    public const TYPE_PLAZA = 'plaza';
    public const TYPE_MEETUP = 'meetup';
    public const TYPE_DM = 'dm';
    public const TYPE_MATCH = 'match';

    protected $fillable = ['type', 'ref_id'];

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'room_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(ChatRoomMember::class, 'room_id');
    }

    /**
     * DM 방 키: 두 사용자 ID를 순서 없이 하나의 정수로 합친다.
     * 작은 ID를 상위 32비트, 큰 ID를 하위 32비트에 넣어 (A,B)와 (B,A)가 같은 값이 된다.
     * → chat_rooms의 UNIQUE(type, ref_id)가 같은 두 사람의 방이 두 개 생기는 것을 막아준다.
     * (사용자 ID가 2^32 ≈ 43억 미만이라는 전제)
     */
    public static function dmKey(int $a, int $b): int
    {
        [$lo, $hi] = $a < $b ? [$a, $b] : [$b, $a];

        return ($lo << 32) | $hi;
    }

    /** DM 방의 두 참여자 ID */
    public function dmPair(): array
    {
        return [(int) ($this->ref_id >> 32), (int) ($this->ref_id & 0xFFFFFFFF)];
    }

    public function otherMemberId(int $me): int
    {
        [$a, $b] = $this->dmPair();

        return $a === $me ? $b : $a;
    }
}

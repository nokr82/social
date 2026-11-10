<?php

namespace App\Services\Safety;

use App\Models\Block;
use App\Services\Friend\FriendService;

/**
 * 차단: 차단 행 추가 + 친구 관계·요청 정리 (디자인: "친구 관계와 대화가 끊겨요").
 * 차단 API와 '신고 + 차단도 함께'가 같은 동작을 하도록 한 곳에 둔다.
 */
class BlockService
{
    public function __construct(private readonly FriendService $friends) {}

    public function block(int $blockerId, int $blockedId): void
    {
        Block::query()->insertOrIgnore(['blocker_id' => $blockerId, 'blocked_id' => $blockedId, 'created_at' => now()]);
        $this->friends->severAll($blockerId, $blockedId);
    }
}

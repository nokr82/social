<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * 친구 위치 갱신 → 공유받는 친구들의 지도 채널(location.{보는 사람})로.
 * - 지도 화면을 열어 둔 사람만 이 채널을 구독하므로, 지도를 안 보면 기기로 좌표가 가지 않는다
 * - 큐를 거치지 않고 바로(ShouldBroadcastNow): 위치는 몇 초만 늦어도 의미가 줄고, 다음 갱신이 곧 온다
 */
class FriendLocationUpdated implements ShouldBroadcastNow
{
    use Dispatchable;

    /** @param  array<int>  $viewerIds */
    public function __construct(public array $viewerIds, public array $position) {}

    public function broadcastOn(): array
    {
        return array_map(fn (int $id) => new PrivateChannel("location.{$id}"), $this->viewerIds);
    }

    public function broadcastAs(): string
    {
        return 'location.updated';
    }

    public function broadcastWith(): array
    {
        return $this->position; // {user_id, lat, lng, accuracy, at}
    }
}

<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/** 위치 공유 끝(끔·차단) → 보던 친구의 지도에서 마커를 바로 지운다 */
class FriendLocationStopped implements ShouldBroadcastNow
{
    use Dispatchable;

    /** @param  array<int>  $viewerIds */
    public function __construct(public int $sharerId, public array $viewerIds) {}

    public function broadcastOn(): array
    {
        return array_map(fn (int $id) => new PrivateChannel("location.{$id}"), $this->viewerIds);
    }

    public function broadcastAs(): string
    {
        return 'location.stopped';
    }

    public function broadcastWith(): array
    {
        return ['user_id' => $this->sharerId];
    }
}

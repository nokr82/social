<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/** 번개 위치 공유 끝(끔·참여 취소·번개 취소·차단) → 보던 사람 지도에서 그 핀을 바로 지움 */
class MeetupLocationStopped implements ShouldBroadcastNow
{
    use Dispatchable;

    /** @param  array<int>  $viewerIds */
    public function __construct(public int $meetupId, public int $sharerId, public array $viewerIds) {}

    public function broadcastOn(): array
    {
        return array_map(fn (int $id) => new PrivateChannel("location.{$id}"), $this->viewerIds);
    }

    public function broadcastAs(): string
    {
        return 'meetup.location.stopped';
    }

    public function broadcastWith(): array
    {
        return ['meetup_id' => $this->meetupId, 'user_id' => $this->sharerId];
    }
}

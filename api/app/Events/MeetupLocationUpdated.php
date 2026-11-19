<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * 번개 참여자 위치 갱신 → 같은 번개 참여자 각자의 개인 채널(location.{보는 사람})로.
 * - 번개 채널(meetup.{id}) 하나로 보내지 않는 이유: 차단한 사람에게는 좌표가 '전송 자체'가 안 돼야 함
 *   (채널로 뿌리고 화면에서 거르면 기기에는 좌표가 도착함)
 * - 이벤트 이름을 친구용(location.updated)과 나눠 친구 지도에 번개 참여자가 섞이지 않게
 */
class MeetupLocationUpdated implements ShouldBroadcastNow
{
    use Dispatchable;

    /** @param  array<int>  $viewerIds */
    public function __construct(public int $meetupId, public array $viewerIds, public array $position) {}

    public function broadcastOn(): array
    {
        return array_map(fn (int $id) => new PrivateChannel("location.{$id}"), $this->viewerIds);
    }

    public function broadcastAs(): string
    {
        return 'meetup.location.updated';
    }

    public function broadcastWith(): array
    {
        return ['meetup_id' => $this->meetupId] + $this->position; // {meetup_id, user_id, lat, lng, accuracy, at}
    }
}

<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * 광장이 닫힘 → 남아 있던 접속자 화면을 목록으로 돌려보낸다.
 * (못 받아도 다음 heartbeat에서 PLAZA_CLOSED(410)로 같은 처리가 된다)
 */
class PlazaClosed implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public int $plazaId, public string $reason) {}

    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel("plaza.{$this->plazaId}");
    }

    public function broadcastAs(): string
    {
        return 'plaza.closed';
    }

    public function broadcastWith(): array
    {
        return ['reason' => $this->reason];
    }
}

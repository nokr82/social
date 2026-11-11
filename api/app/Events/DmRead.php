<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/** 상대가 읽음 → 내 메시지에 '읽음' 표시 */
class DmRead implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public int $roomId, public int $readerId, public int $lastReadId) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("chat.{$this->roomId}");
    }

    public function broadcastAs(): string
    {
        return 'dm.read';
    }

    public function broadcastWith(): array
    {
        return ['user_id' => $this->readerId, 'last_read_id' => $this->lastReadId];
    }
}

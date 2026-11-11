<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * 방장이 한 명을 내보냄.
 * - 당사자 화면: 목록으로 나감
 * - 다른 사람 화면: 그 아바타를 지우고, 그 사람이 보내는 whisper(이동·표정)를 무시
 *   (웹소켓 구독은 서버가 강제로 끊을 수 없어서 받는 쪽에서 걸러냄)
 */
class PlazaMemberKicked implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public int $plazaId, public int $userId) {}

    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel("plaza.{$this->plazaId}");
    }

    public function broadcastAs(): string
    {
        return 'plaza.member_kicked';
    }

    public function broadcastWith(): array
    {
        return ['user_id' => $this->userId];
    }
}

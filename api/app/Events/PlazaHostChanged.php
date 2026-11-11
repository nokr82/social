<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * 방장 위임 → 광장 접속자에게 '○○님이 새 방장이 됐어요' 표시.
 * host는 AvatarSummary 형태 (닉네임·아바타만, 성별·나이 없음)
 */
class PlazaHostChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public int $plazaId, public array $host) {}

    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel("plaza.{$this->plazaId}");
    }

    public function broadcastAs(): string
    {
        return 'plaza.host_changed';
    }

    public function broadcastWith(): array
    {
        return ['host' => $this->host];
    }
}

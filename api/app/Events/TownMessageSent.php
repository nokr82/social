<?php

namespace App\Events;

use App\Http\Resources\ChatMessageResource;
use App\Models\ChatMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** 상시 광장 구역 채팅 → 같은 구역·채널 접속자에게만 (presence-town.{광장}.{구역}.{채널}) */
class TownMessageSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $plazaId,
        public string $zone,
        public int $channel,
        public ChatMessage $message,
    ) {}

    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel("town.{$this->plazaId}.{$this->zone}.{$this->channel}");
    }

    public function broadcastAs(): string
    {
        return 'message.sent'; // 일반 광장과 같은 이벤트 이름 → 앱의 메시지 처리 재사용
    }

    public function broadcastWith(): array
    {
        return (new ChatMessageResource($this->message))->resolve();
    }
}

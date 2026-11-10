<?php

namespace App\Events;

use App\Http\Resources\ChatMessageResource;
use App\Models\ChatMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * 광장 채팅 메시지 → Reverb → 광장 접속자 전원.
 * ShouldBroadcast(큐 경유): API 응답이 Reverb 전송을 기다리지 않게 한다.
 */
class PlazaMessageSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public int $plazaId, public ChatMessage $message) {}

    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel("plaza.{$this->plazaId}");
    }

    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    public function broadcastWith(): array
    {
        return (new ChatMessageResource($this->message))->resolve();
    }
}

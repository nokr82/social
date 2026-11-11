<?php

namespace App\Events;

use App\Http\Resources\ChatMessageResource;
use App\Models\ChatMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * DM 새 메시지 → 두 곳으로
 * - chat.{room}: 대화방을 보고 있는 상대 화면
 * - App.Models.User.{상대}: 채팅 목록·배지 갱신 (방을 안 보고 있어도)
 */
class DmMessageSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public int $roomId, public int $recipientId, public ChatMessage $message) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("chat.{$this->roomId}"),
            new PrivateChannel("App.Models.User.{$this->recipientId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'dm.sent';
    }

    public function broadcastWith(): array
    {
        return ['room_id' => $this->roomId] + (new ChatMessageResource($this->message))->resolve();
    }
}

<?php

namespace App\Events;

use App\Http\Resources\ChatMessageResource;
use App\Models\ChatMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** 번개 채팅방 메시지(일반·안내) → meetup.{id} 참여자 */
class MeetupMessageSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public int $meetupId, public ChatMessage $message) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("meetup.{$this->meetupId}");
    }

    public function broadcastAs(): string
    {
        return 'meetup.message';
    }

    public function broadcastWith(): array
    {
        return (new ChatMessageResource($this->message))->resolve();
    }
}

<?php

namespace App\Services\Chat;

use App\Notifications\Notify;
use App\Events\DmMessageSent;
use App\Events\DmRead;
use App\Exceptions\DomainException;
use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\ChatRoomMember;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 친구 1:1 대화.
 * 규칙: 친구끼리만 (친구가 아니게 되거나 차단되면 방은 남아도 읽기·쓰기 불가)
 */
class DmService
{
    /** 친구와의 방을 찾거나 만든다. A→B, B→A 모두 같은 방 */
    public function openWith(User $me, int $friendId): ChatRoom
    {
        $this->assertCanTalk($me, $friendId);
        $key = ChatRoom::dmKey($me->id, $friendId);

        $room = ChatRoom::where('type', ChatRoom::TYPE_DM)->where('ref_id', $key)->first();
        if ($room) {
            return $room;
        }

        try {
            return DB::transaction(function () use ($key, $me, $friendId) {
                $room = ChatRoom::create(['type' => ChatRoom::TYPE_DM, 'ref_id' => $key]);
                ChatRoomMember::insert([
                    ['room_id' => $room->id, 'user_id' => $me->id, 'last_read_id' => 0, 'created_at' => now()],
                    ['room_id' => $room->id, 'user_id' => $friendId, 'last_read_id' => 0, 'created_at' => now()],
                ]);

                return $room;
            });
        } catch (UniqueConstraintViolationException) {
            // 두 사람이 동시에 열었을 때: 먼저 만든 쪽 방을 쓴다
            return ChatRoom::where('type', ChatRoom::TYPE_DM)->where('ref_id', $key)->firstOrFail();
        }
    }

    public function send(User $me, ChatRoom $room, string $body): ChatMessage
    {
        $other = $this->assertMember($me, $room);

        $message = $room->messages()->create(['sender_id' => $me->id, 'body' => $body]);
        // 내가 보낸 메시지까지는 읽은 것
        $this->markRead($me, $room, $message->id, broadcast: false);

        $message->load('sender.avatar');
        broadcast(new DmMessageSent($room->id, $other, $message))->toOthers();
        // 상대 폰 푸시 (설정·구독이 있을 때만 실제로 나감)
        if ($to = User::find($other)) {
            Notify::dm($me, $to, $room->id, $body);
        }

        return $message;
    }

    /** @return Collection<int, ChatMessage> 최신순 */
    public function messages(User $me, ChatRoom $room, ?int $beforeId, int $limit = 50): Collection
    {
        $this->assertMember($me, $room);

        return $room->messages()->with('sender.avatar')
            ->when($beforeId, fn ($q) => $q->where('id', '<', $beforeId))
            ->orderByDesc('id')->limit($limit)->get();
    }

    /** 읽음 위치를 앞으로만 옮긴다 (뒤로 가지 않음) */
    public function markRead(User $me, ChatRoom $room, int $lastId, bool $broadcast = true): void
    {
        $updated = ChatRoomMember::where('room_id', $room->id)->where('user_id', $me->id)
            ->where('last_read_id', '<', $lastId)
            ->update(['last_read_id' => $lastId]);

        if ($updated && $broadcast) {
            broadcast(new DmRead($room->id, $me->id, $lastId))->toOthers();
        }
    }

    /** 상대가 어디까지 읽었는지 ('읽음' 표시용) */
    public function otherLastRead(User $me, ChatRoom $room): int
    {
        return (int) ChatRoomMember::where('room_id', $room->id)
            ->where('user_id', $room->otherMemberId($me->id))->value('last_read_id');
    }

    /**
     * 내 대화 목록: 상대·마지막 메시지·안 읽은 수, 최근 대화 순.
     * 쿼리 3번(방, 마지막 메시지, 안 읽은 수)으로 끝내 방 개수만큼 쿼리하지 않는다.
     */
    public function inbox(User $me): Collection
    {
        $friendIds = $me->friendIds();
        $memberships = ChatRoomMember::where('user_id', $me->id)
            ->join('chat_rooms', 'chat_rooms.id', '=', 'chat_room_members.room_id')
            ->where('chat_rooms.type', ChatRoom::TYPE_DM)
            ->get(['chat_rooms.id as room_id', 'chat_rooms.ref_id', 'chat_room_members.last_read_id']);

        // 친구 관계가 유지되는 방만 (친구가 아니게 되면 목록에서 사라짐)
        $memberships = $memberships->filter(function ($m) use ($me, $friendIds) {
            $room = new ChatRoom(['ref_id' => $m->ref_id]);
            $m->other_id = $room->otherMemberId($me->id);

            return in_array($m->other_id, $friendIds, true);
        });
        if ($memberships->isEmpty()) {
            return collect();
        }
        $roomIds = $memberships->pluck('room_id');

        $lastMessages = ChatMessage::whereIn('id', ChatMessage::selectRaw('MAX(id)')->whereIn('room_id', $roomIds)->groupBy('room_id'))
            ->get()->keyBy('room_id');

        $unread = ChatMessage::query()
            ->join('chat_room_members as m', fn ($j) => $j->on('m.room_id', '=', 'chat_messages.room_id')->where('m.user_id', $me->id))
            ->whereIn('chat_messages.room_id', $roomIds)
            ->whereColumn('chat_messages.id', '>', 'm.last_read_id')
            ->where('chat_messages.sender_id', '!=', $me->id)
            ->groupBy('chat_messages.room_id')
            ->selectRaw('chat_messages.room_id, COUNT(*) as n')
            ->pluck('n', 'room_id');

        $others = User::with('avatar')->whereIn('id', $memberships->pluck('other_id'))->get()->keyBy('id');

        return $memberships
            ->filter(fn ($m) => $lastMessages->has($m->room_id)) // 아직 한 마디도 안 한 방은 숨김
            ->map(fn ($m) => [
                'room_id' => $m->room_id,
                'user' => $others->get($m->other_id),
                'last_message' => $lastMessages->get($m->room_id),
                'unread' => (int) ($unread[$m->room_id] ?? 0),
            ])
            ->sortByDesc(fn ($r) => $r['last_message']->id)
            ->values();
    }

    /** 방 참여자이고 지금도 친구인지 확인. 상대 ID 반환 */
    public function assertMember(User $me, ChatRoom $room): int
    {
        if ($room->type !== ChatRoom::TYPE_DM || ! in_array($me->id, $room->dmPair(), true)) {
            throw new DomainException('NOT_FOUND', '대화방을 찾을 수 없어요', 404);
        }
        $other = $room->otherMemberId($me->id);
        $this->assertCanTalk($me, $other);

        return $other;
    }

    private function assertCanTalk(User $me, int $otherId): void
    {
        if ($me->isBlockedWith($otherId)) {
            throw new DomainException('NOT_FOUND', '대화방을 찾을 수 없어요', 404);
        }
        if (! $me->isFriendWith($otherId)) {
            throw new DomainException('NOT_FRIENDS', '1:1 대화는 친구끼리만 할 수 있어요', 403);
        }
    }
}

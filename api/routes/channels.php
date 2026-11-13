<?php

use App\Http\Resources\AvatarSummary;
use App\Models\ChatRoom;
use App\Models\Meetup;
use App\Models\MeetupMember;
use App\Models\Plaza;
use App\Models\User;
use App\Services\Plaza\PlazaPresence;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

/*
| 1:1 대화 채널: chat.{room} — 방 참여자이면서 지금도 친구일 때만
*/
Broadcast::channel('chat.{room}', function (User $user, ChatRoom $room) {
    if ($room->type !== ChatRoom::TYPE_DM || ! in_array($user->id, $room->dmPair(), true)) {
        return false;
    }
    $other = $room->otherMemberId($user->id);

    return $user->isActive() && $user->isFriendWith($other) && ! $user->isBlockedWith($other);
});

/*
| 번개 채팅방 채널: meetup.{id} — 참여 확정자만
*/
Broadcast::channel('meetup.{meetup}', function (User $user, Meetup $meetup) {
    return $user->isActive() && MeetupMember::where('meetup_id', $meetup->id)
        ->where('user_id', $user->id)->where('status', MeetupMember::JOINED)->exists();
});

/*
| 광장 presence 채널: presence-plaza.{id}
| - 입장 API(POST /plazas/{id}/enter)를 거친 사람만 구독 가능
| - 반환값이 다른 접속자에게 보이는 '내 정보' → 성별·나이 없이 닉네임·아바타만
| - 아바타 이동 좌표는 서버를 거치지 않고 client event(whisper)로 주고받는다
*/
Broadcast::channel('plaza.{plaza}', function (User $user, Plaza $plaza) {
    if (! $user->isActive() || ! app(PlazaPresence::class)->has($plaza->id, $user->id)) {
        return false;
    }

    return AvatarSummary::of($user->loadMissing('avatar'));
});

/*
| 상시 광장 구역 presence 채널: presence-town.{광장}.{구역}.{채널}
| - 입장 API(POST /plazas/{id}/town/enter)로 그 구역·채널에 배정된 사람만 구독 가능
| - 같은 구역·채널 사람끼리만 서로 보이고(아바타) 대화한다
*/
Broadcast::channel('town.{plaza}.{zone}.{channel}', function (User $user, Plaza $plaza, string $zone, string $channel) {
    if (! $user->isActive() || ! $plaza->isTown()
        || ! app(PlazaPresence::class)->has("{$plaza->id}:{$zone}:{$channel}", $user->id)) {
        return false;
    }

    return AvatarSummary::of($user->loadMissing('avatar'));
});

/*
| 친구 지도 채널: location.{보는 사람} — 본인만. 지도 화면을 연 동안만 구독한다
| (서버는 공유받는 사람의 채널로만 보내므로, 남의 위치를 받으려면 그 사람이 나에게 공유해야 함)
*/
Broadcast::channel('location.{viewer}', function (User $user, $viewer) {
    return $user->isActive() && (int) $user->id === (int) $viewer;
});

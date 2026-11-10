<?php

use App\Http\Resources\AvatarSummary;
use App\Models\Plaza;
use App\Models\User;
use App\Services\Plaza\PlazaPresence;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
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

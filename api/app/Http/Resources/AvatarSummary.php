<?php

namespace App\Http\Resources;

use App\Models\User;

/** 채팅·목록에 들어가는 최소 사용자 정보 (성별·나이 없음) */
final class AvatarSummary
{
    public static function of(?User $user): ?array
    {
        if (! $user) {
            return null;
        }

        return [
            'id' => $user->id,
            'nickname' => $user->nickname,
            'avatar' => $user->avatar?->parts,
            'avatar_version' => $user->avatar?->version,
        ];
    }
}

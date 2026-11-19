<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 번개 친구 초대 기록 (규칙은 MeetupInviteService) */
class MeetupInvite extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['meetup_id', 'inviter_id', 'invitee_id'];
}

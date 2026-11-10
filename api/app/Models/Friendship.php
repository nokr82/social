<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 친구 관계 1건 = 양방향 2행 (A→B, B→A) */
class Friendship extends Model
{
    public $incrementing = false;
    protected $primaryKey = null;
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'friend_id', 'plaza_id'];
}

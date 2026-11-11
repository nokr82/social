<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatRoomMember extends Model
{
    public $incrementing = false;
    protected $primaryKey = null; // 복합 PK → 갱신은 쿼리 빌더로
    public const UPDATED_AT = null;

    protected $attributes = ['last_read_id' => 0];

    protected $fillable = ['room_id', 'user_id', 'last_read_id'];
}

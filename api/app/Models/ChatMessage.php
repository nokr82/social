<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatMessage extends Model
{
    public const UPDATED_AT = null;

    // DB 기본값과 동일 (create() 직후 응답에 type이 null로 나가지 않도록)
    protected $attributes = ['type' => 'text'];

    protected $fillable = ['room_id', 'sender_id', 'type', 'body'];

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatRoom extends Model
{
    public const TYPE_PLAZA = 'plaza';
    public const TYPE_MEETUP = 'meetup';
    public const TYPE_DM = 'dm';
    public const TYPE_MATCH = 'match';

    protected $fillable = ['type', 'ref_id'];

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'room_id');
    }
}

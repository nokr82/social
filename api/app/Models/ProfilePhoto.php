<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProfilePhoto extends Model
{
    public const PUBLIC = 'public';
    public const PRIVATE = 'private';
    public const MAX_PER_USER = 9;

    // DB 기본값과 동일 (create() 직후 인스턴스에도 반영)
    protected $attributes = ['visibility' => self::PUBLIC, 'like_count' => 0];

    protected $fillable = ['user_id', 'path', 'visibility', 'sort_order', 'like_count'];

    protected $hidden = ['path']; // 저장소 경로는 응답에 노출하지 않음 (URL은 서명해서 따로)

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isPublic(): bool
    {
        return $this->visibility === self::PUBLIC;
    }
}

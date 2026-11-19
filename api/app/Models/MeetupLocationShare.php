<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 번개 '모이는 중' 위치 공유 켬 (행이 있으면 켜짐). 규칙은 MeetupLocationService */
class MeetupLocationShare extends Model
{
    public $incrementing = false;
    protected $primaryKey = null; // 복합 PK → 갱신·삭제는 쿼리 빌더로
    public const UPDATED_AT = null;

    protected $fillable = ['meetup_id', 'user_id'];

    public function meetup(): BelongsTo
    {
        return $this->belongsTo(Meetup::class);
    }
}

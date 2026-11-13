<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

/**
 * 위치 제공사실 확인자료 (위치정보법). 좌표는 남기지 않고 '누가 누구의 위치를 언제 받았는지'만.
 * 보관: KEEP_DAYS 지나면 model:prune으로 삭제
 */
class LocationAccessLog extends Model
{
    use Prunable;

    public const KEEP_DAYS = 365;

    public const UPDATED_AT = null;

    protected $fillable = ['sharer_id', 'viewer_id'];

    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(self::KEEP_DAYS));
    }
}

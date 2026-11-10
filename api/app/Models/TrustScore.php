<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 매너점수. 점수 자체는 앱에 내려주지 않는다(산식 역추적 방지).
 */
class TrustScore extends Model
{
    protected $primaryKey = 'user_id';
    public $incrementing = false;
    public const CREATED_AT = null;

    // DB 기본값과 동일
    protected $attributes = ['score' => 500, 'attend_count' => 0, 'noshow_count' => 0, 'report_count' => 0];

    protected $fillable = ['user_id', 'score', 'attend_count', 'noshow_count', 'report_count'];
}

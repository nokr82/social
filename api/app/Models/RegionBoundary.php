<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 행정동 경계 1개 + 소속 시·군·구 (regions:import 로만 채움, 앱에서 수정하지 않음) */
class RegionBoundary extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'min_lat' => 'float',
            'max_lat' => 'float',
            'min_lng' => 'float',
            'max_lng' => 'float',
        ];
    }
}

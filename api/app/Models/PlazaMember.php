<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 주민 광장 참여자. '나가기'를 누르기 전까지 유지된다 (접속 여부와 별개).
 * 복합 PK라 인스턴스 save() 대신 쿼리 빌더(insertOrIgnore·where()->update())로만 쓴다.
 */
class PlazaMember extends Model
{
    public $incrementing = false;
    protected $primaryKey = null; // 복합 PK → 갱신은 쿼리 빌더로
    public $timestamps = false;

    protected $attributes = ['last_read_id' => 0];

    protected $fillable = ['plaza_id', 'user_id', 'last_read_id', 'joined_at', 'last_seen_at'];

    protected function casts(): array
    {
        return ['joined_at' => 'datetime', 'last_seen_at' => 'datetime'];
    }
}

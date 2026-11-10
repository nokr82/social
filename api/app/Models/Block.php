<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Block extends Model
{
    public $incrementing = false;
    protected $primaryKey = null;       // 복합 PK → Eloquent 저장은 create()만 사용
    public const UPDATED_AT = null;

    protected $fillable = ['blocker_id', 'blocked_id'];
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Avatar extends Model
{
    protected $primaryKey = 'user_id';
    public $incrementing = false;

    // DB 기본값과 동일 (가입 직후 avatar_version이 null로 나가지 않도록)
    protected $attributes = ['version' => 1];

    protected $fillable = ['user_id', 'parts', 'version'];

    protected function casts(): array
    {
        return ['parts' => 'array'];
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PhotoLike extends Model
{
    public $incrementing = false;
    protected $primaryKey = null; // 복합 PK
    public const UPDATED_AT = null;

    protected $fillable = ['photo_id', 'user_id'];
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Report extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REJECTED = 'rejected';

    // DB 기본값과 동일
    protected $attributes = ['status' => self::STATUS_PENDING];

    protected $fillable = ['reporter_id', 'target_id', 'target_type', 'target_ref_id', 'reason', 'detail', 'status'];
}

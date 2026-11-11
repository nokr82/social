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

    protected $fillable = ['reporter_id', 'target_id', 'target_type', 'target_ref_id', 'reason', 'detail', 'status', 'handled_by', 'action', 'admin_note', 'handled_at'];

    protected function casts(): array
    {
        return ['handled_at' => 'datetime'];
    }

    public function reporter(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function target(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'target_id')->withTrashed(); // 탈퇴자도 처리 가능하게
    }

    /** 처리한 관리자 */
    public function handler(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Admin::class, 'handled_by');
    }
}

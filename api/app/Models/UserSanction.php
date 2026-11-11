<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserSanction extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'admin_id', 'type', 'until', 'reason'];

    protected function casts(): array
    {
        return ['until' => 'datetime'];
    }

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /** null = 시스템(신고 누적 자동 정지, 기간 만료 해제) */
    public function admin(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }
}

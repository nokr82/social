<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, SoftDeletes;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_BANNED = 'banned';
    public const STATUS_WITHDRAWN = 'withdrawn';

    // DB 기본값과 같게: create() 직후 인스턴스에도 status가 채워지도록
    protected $attributes = ['status' => self::STATUS_ACTIVE];

    // status는 관리자·시스템만 바꾼다 → fillable에서 제외
    protected $fillable = ['nickname', 'region_cell', 'last_seen_at'];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function identity(): HasOne
    {
        return $this->hasOne(UserIdentity::class);
    }

    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class);
    }

    public function avatar(): HasOne
    {
        return $this->hasOne(Avatar::class);
    }

    public function trustScore(): HasOne
    {
        return $this->hasOne(TrustScore::class);
    }

    /** 사진 (sort_order 0 = 대표사진) */
    public function photos(): HasMany
    {
        return $this->hasMany(ProfilePhoto::class)->orderBy('sort_order');
    }

    public function isFriendWith(int $userId): bool
    {
        return Friendship::where('user_id', $this->id)->where('friend_id', $userId)->exists();
    }

    /** @return array<int> */
    public function friendIds(): array
    {
        return Friendship::where('user_id', $this->id)->pluck('friend_id')->map(fn ($id) => (int) $id)->all();
    }

    /** 차단 관계(양방향)인지 */
    public function isBlockedWith(int $userId): bool
    {
        return in_array($userId, $this->blockedUserIds(), true);
    }

    /**
     * 나와 차단 관계(내가 차단 + 나를 차단)인 사용자 ID 목록.
     * 차단은 양방향 적용이 원칙이라 두 방향을 합친다.
     *
     * @return array<int>
     */
    public function blockedUserIds(): array
    {
        return Block::where('blocker_id', $this->id)->pluck('blocked_id')
            ->merge(Block::where('blocked_id', $this->id)->pluck('blocker_id'))
            ->map(fn ($id) => (int) $id)
            ->unique()->values()->all();
    }
}

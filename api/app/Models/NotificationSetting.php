<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationSetting extends Model
{
    public const GROUPS = ['friend', 'chat', 'meetup'];

    protected $primaryKey = 'user_id';
    public $incrementing = false;
    public const CREATED_AT = null;

    // DB 기본값과 동일 (행이 없을 때 new로 만든 객체도 같은 값)
    protected $attributes = ['friend' => true, 'chat' => true, 'meetup' => true, 'night_quiet' => false];

    protected $fillable = ['user_id', 'friend', 'chat', 'meetup', 'night_quiet'];

    protected function casts(): array
    {
        return ['friend' => 'boolean', 'chat' => 'boolean', 'meetup' => 'boolean', 'night_quiet' => 'boolean'];
    }

    /** 한국 시간 22:00~07:59 */
    public static function isNight(): bool
    {
        $h = (int) now('Asia/Seoul')->format('G');

        return $h >= 22 || $h < 8;
    }

    public function allowsPush(string $group): bool
    {
        return (bool) $this->{$group} && ! ($this->night_quiet && self::isNight());
    }
}

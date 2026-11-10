<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Profile extends Model
{
    protected $primaryKey = 'user_id';
    public $incrementing = false;

    protected $fillable = ['user_id', 'bio', 'interests'];

    protected function casts(): array
    {
        return ['interests' => 'array'];
    }
}

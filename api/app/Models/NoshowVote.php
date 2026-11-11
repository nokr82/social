<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NoshowVote extends Model
{
    public $incrementing = false;
    protected $primaryKey = null;
    public const UPDATED_AT = null;

    protected $fillable = ['meetup_id', 'voter_id', 'target_id'];
}

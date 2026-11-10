<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BannedIdentity extends Model
{
    protected $primaryKey = 'ci_hash';
    protected $keyType = 'string';
    public $incrementing = false;
    public const UPDATED_AT = null;

    protected $fillable = ['ci_hash', 'reason'];
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Player extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'phone',
    ];
}

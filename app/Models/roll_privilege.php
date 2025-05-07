<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class roll_privilege extends Model
{
    protected $fillable = [
        'privilege_id',
        'roll_id',
        'active',
        'user_id'
    ];
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class user_history extends Model
{
    protected $fillable = [
        'id_roll',
        'user_data',
        'user_id',
        'active',
    ];

    protected $casts = [
        'user_data' => 'array',
        'active' => 'boolean',
    ];
}


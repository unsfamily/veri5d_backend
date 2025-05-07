<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class other extends Model
{
    protected $fillable = [
        'titel',
        'value',
        'user_id',
        'active',
    ];

    protected $casts = [
        'value' => 'array',
        'active' => 'boolean',
    ];
}
// GK@0318
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class vendor extends Model
{
    protected $fillable = [
        'shop_name',
        'slug',
        'shop_data',
        'user_id',
        'add_by',
        'active',
    ];
    protected $casts = [
        'shop_data' => 'array',
        'active' => 'boolean',
    ];
}



<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class used_code extends Model
{
    protected $fillable = [
        'code_id',
        'order_id',
        'offer_information',
        'user_id',
        'active',
        'status'
    ];
    protected $casts = [
        'offer_information' => 'array',
        'active' => 'boolean',
    ];
}




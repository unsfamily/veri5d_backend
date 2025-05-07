<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class offer_code extends Model
{
    
    protected $fillable = [
        'code',
        'description',
        'titel',
        'amount_type',
        'amount',
        'expiry_date',
        'limit_per_user',
        'totel_limit',
        'promo_visibility',
        'card_type',
        'other',
        'image',
        'user_id',
        'active',
    ];
    protected $casts = [
        'other' => 'array',
        'active' => 'boolean',
    ];
}


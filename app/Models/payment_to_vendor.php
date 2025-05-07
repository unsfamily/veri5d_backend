<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class payment_to_vendor extends Model
{
    protected $fillable = [
        'order_id',
        'product_id',
        'user_id',
        'active',
        'payment_mode',
        'payment_id',
        'payment_amount',
    ];
    protected $casts = [
        'active' => 'boolean',
    ];
}



<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class user_payment_request extends Model
{
    protected $fillable = [
        'amount_req',
        'status', 
        'user_id',
        'active',
        'payment_mode',
        'payment_id',
        'payment_proof',
    ];
}



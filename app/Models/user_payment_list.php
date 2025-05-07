<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class user_payment_list extends Model
{
    protected $fillable = [
        'Share',
        'profit_share', 
        'order_id',
        'product_id',
        'user_id',
        'active',
        'payment_mode',
        'payment_id',
        'id_roll',
    ];
    public function finduser()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
    public function roleuser()
    {
        return $this->belongsTo(roll::class, 'id_roll');
    }
    public function findorders()
    {
        return $this->belongsTo(Order_tracking::class, 'order_id');
    }


}

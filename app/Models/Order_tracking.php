<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order_tracking extends Model
{
    protected $fillable = [
        'product_id',
        'quantity',
        'totel_price',
        'status', 
        'shipping_details',
        'shipped_at',
        'delivered_at',
        'tracking_number',
        'user_id',
        'payment_mode',
        'payment_id',
        'active',
        'additional_information',
        'delivery_fee',
        'tax_price',
        'tax_Rate'
    ];
    protected $casts = [
        'additional_information' => 'array',
        'shipping_details' => 'array',
    ];
    public function finduser()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
    public function findp()
    {
        return $this->belongsTo(product::class, 'product_id');
    }
    
    public function images()
    {
        return $this->hasMany(product_image::class, 'product_id');
    }
    public function share()
    {
        return $this->hasMany(user_payment_list::class, 'order_id');
    }


}
            

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class add_to_cart extends Model
{
    protected $fillable = [
        'product_id',
        'quantity',
        'user_id',
        'cart',
        'active',
    ];

    // public function images()
    // {
    //     return $this->hasMany(product_image::class, 'product_id');
    // }

    public function images()
    {
        return $this->hasMany(product_image::class, 'product_id', 'product_id');
    }





    public function product()
    {
        return $this->belongsTo(product::class, 'product_id');
    }
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}

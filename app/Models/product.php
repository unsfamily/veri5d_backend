<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class product extends Model
{
    protected $fillable = [
        'name',
        'data',
        'total_price',
        'profit',
        'shop_id',
        'parent_id',
        'type',
        'user_id',
        'add_by',
        'active',
        'category_id',
        'brand_id'
    ];
    protected $casts = [
        'data' => 'array',
        'active' => 'boolean',
    ];

    public function images()
    {
        return $this->hasMany(product_image::class, 'product_id');
    }
    public function catname()
    {
        return $this->belongsTo(product_categorie::class, 'category_id');
    }
    public function brand()
    {
        return $this->belongsTo(product_attribute::class, 'brand_id');
    }  
    public function vendor()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
    public function children()
    {
        return $this->hasMany(product::class, 'parent_id');
    }

    public function reviews()
    {
        return $this->hasMany(product_review::class, 'product_id');
    }

    public function order()
    {
        return $this->hasMany(Order_tracking::class, 'product_id');
    }

    public function wishlist()
    {
        return $this->hasMany(add_to_cart::class, 'product_id');
    }
    
}



<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class product_image extends Model
{
    protected $fillable = [
        'productImages',
        'product_id',
        'user_id',
        'active',
    ];


    public function product()
    {
        return $this->belongsTo(product::class, 'product_id');
    }
}






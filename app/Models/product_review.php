<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class product_review extends Model
{
    protected $fillable = [
        'product_id',
        'rating',
        'comment',
        'user_id',
        'active',
        'image',
    ];
    public function finduser()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
    public function product()
    {
        return $this->belongsTo(product::class, 'product_id');
    }
}


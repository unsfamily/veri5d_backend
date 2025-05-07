<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class product_attribute extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'user_id',
        'attributes_type',
        'attributes',
        'active',
    ];
    protected $casts = [
        'attributes' => 'array',
        'active' => 'boolean',
    ];
}


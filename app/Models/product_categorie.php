<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class product_categorie extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'image',
        'user_id',
        'active',
        'parent_id',
    ];
    protected $casts = [
        'description' => 'array',
        'active' => 'boolean',
    ];

    public function subcategories()
    {
        return $this->hasMany(product_categorie::class, 'parent_id');
    }

    public function parent()
    {
        return $this->belongsTo(product_categorie::class, 'parent_id');
    }
}


            
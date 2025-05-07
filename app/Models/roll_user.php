<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class roll_user extends Model
{
    protected $fillable = [
        'add_by',
        'id_roll',
        'active',
        'under_user',
        'sub_under_user',
        'user_id'
    ];

    // Define relationship with itself for parent-child mapping
    public function parent()
    {
        return $this->belongsTo(roll_user::class, 'under_user');
    }

    public function children()
    {
        return $this->hasMany(roll_user::class, 'under_user');
    }
    public function finduser()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function findrole()
    {
        return $this->belongsTo(roll::class, 'id_roll');
    }


}

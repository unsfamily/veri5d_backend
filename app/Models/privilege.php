<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class privilege extends Model
{
    protected $fillable = [
        'privilege_name',
        'user_id',
        'active'
    ];

    public function rolls()
    {
        return $this->belongsToMany(Roll::class, 'roll_privileges', 'privilege_id', 'roll_id')
                    ->where('roll_privileges.active', 1);
    }
}

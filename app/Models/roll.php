<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class roll extends Model
{
    protected $fillable = [
        'roll_name',
        'share',
        'active',
        'parent_id',
        'user_id'
    ];
    public function children()
    {
        return $this->hasMany(Roll::class, 'parent_id');
    }
    public function privileges()
    {
        return $this->belongsToMany(privilege::class, 'roll_privileges', 'roll_id', 'privilege_id')
                    ->where('roll_privileges.active', 1);
    }
}

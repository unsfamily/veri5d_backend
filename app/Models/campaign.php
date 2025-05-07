<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class campaign extends Model
{
    protected $fillable = [
        'title',
        'notification_type',
        'message',
        'select_users',
        'schedule_date',
        // 'request_user_count',
        'user_id',
        'image',
        'active',
    ];
}



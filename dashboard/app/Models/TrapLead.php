<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrapLead extends Model
{
    protected $fillable = [
        'name',
        'email',
        'domain',
        'company',
        'notes',
        'source',
        'ip_address',
        'user_agent',
    ];
}


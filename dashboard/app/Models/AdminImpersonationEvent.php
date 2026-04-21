<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminImpersonationEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'admin_user_id',
        'admin_email',
        'tenant_id',
        'route_action',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'admin_user_id' => 'integer',
            'tenant_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}

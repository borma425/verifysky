<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantDomain extends Model
{
    protected $fillable = [
        'tenant_id',
        'hostname',
        'cname_target',
        'cloudflare_custom_hostname_id',
        'hostname_status',
        'ssl_status',
        'security_mode',
        'force_captcha',
        'ownership_verification',
        'thresholds',
        'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'force_captcha' => 'boolean',
            'ownership_verification' => 'array',
            'thresholds' => 'array',
            'verified_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}

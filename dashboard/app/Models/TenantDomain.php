<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantDomain extends Model
{
    public const APEX_MODE_WWW_REDIRECT = 'www_redirect';

    public const APEX_MODE_DIRECT_APEX = 'direct_apex';

    public const APEX_MODE_SUBDOMAIN_ONLY = 'subdomain_only';

    public const REDIRECT_STATUS_UNCHECKED = 'unchecked';

    public const REDIRECT_STATUS_ACTIVE = 'active';

    public const REDIRECT_STATUS_WARNING = 'warning';

    public const REDIRECT_STATUS_ACTION_REQUIRED = 'action_required';

    public const REDIRECT_STATUS_FAILED = 'failed';

    protected $fillable = [
        'tenant_id',
        'hostname',
        'requested_domain',
        'canonical_hostname',
        'apex_mode',
        'dns_provider',
        'apex_redirect_status',
        'apex_redirect_checked_at',
        'cname_target',
        'origin_server',
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
            'apex_redirect_checked_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}

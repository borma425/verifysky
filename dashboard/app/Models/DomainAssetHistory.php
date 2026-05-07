<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DomainAssetHistory extends Model
{
    public const TYPE_REGISTRABLE_DOMAIN = 'registrable_domain';

    public const TYPE_SHARED_HOSTNAME = 'shared_hostname';

    protected $fillable = [
        'asset_key',
        'asset_type',
        'registrable_domain',
        'hostname',
        'pro_trial_granted_at',
        'pro_trial_tenant_id',
        'pro_trial_grant_id',
        'quarantined_until',
        'last_removed_at',
        'last_removed_tenant_id',
        'last_removal_reason',
        'metadata_json',
    ];

    protected function casts(): array
    {
        return [
            'pro_trial_granted_at' => 'datetime',
            'quarantined_until' => 'datetime',
            'last_removed_at' => 'datetime',
            'metadata_json' => 'array',
        ];
    }
}

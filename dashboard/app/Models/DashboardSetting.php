<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class DashboardSetting extends Model
{
    private const ENCRYPTED_PREFIX = 'enc:v1:';

    private const SENSITIVE_KEYS = [
        'cf_api_token',
        'openrouter_api_key',
        'jwt_secret',
        'es_admin_token',
    ];

    protected $fillable = [
        'key',
        'value',
    ];

    public function setValueAttribute(?string $value): void
    {
        $normalized = (string) ($value ?? '');
        if (!$this->isSensitiveKey()) {
            $this->attributes['value'] = $normalized;
            return;
        }

        if ($normalized === '') {
            $this->attributes['value'] = '';
            return;
        }

        if (str_starts_with($normalized, self::ENCRYPTED_PREFIX)) {
            $this->attributes['value'] = $normalized;
            return;
        }

        $this->attributes['value'] = self::ENCRYPTED_PREFIX.Crypt::encryptString($normalized);
    }

    public function getValueAttribute(?string $value): string
    {
        $raw = (string) ($value ?? '');
        if ($raw === '' || !$this->isSensitiveKey()) {
            return $raw;
        }

        if (!str_starts_with($raw, self::ENCRYPTED_PREFIX)) {
            return $raw;
        }

        $payload = substr($raw, strlen(self::ENCRYPTED_PREFIX));
        if ($payload === false || $payload === '') {
            return '';
        }

        try {
            return Crypt::decryptString($payload);
        } catch (\Throwable) {
            return '';
        }
    }

    public static function sensitiveKeys(): array
    {
        return self::SENSITIVE_KEYS;
    }

    public function isSensitiveKey(): bool
    {
        $key = (string) ($this->attributes['key'] ?? $this->key ?? '');
        return in_array($key, self::SENSITIVE_KEYS, true);
    }
}

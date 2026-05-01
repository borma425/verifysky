<?php

namespace App\Support;

use Illuminate\Support\Str;

class TenantLoginPath
{
    public const ACCOUNT_PREFIX = 'account';

    public const RESERVED_PATHS = [
        'login',
        'logout',
        'dashboard',
        'domains',
        'logs',
        'settings',
        'billing',
        'admin',
        'api',
        'actions',
    ];

    public static function normalize(string $path): string
    {
        $candidate = trim(strtolower($path));
        $candidate = ltrim($candidate, '/');
        $candidate = preg_replace('/[^a-z0-9\/_-]/', '', $candidate) ?? '';
        $candidate = preg_replace('#/+#', '/', $candidate) ?? '';

        return trim($candidate, '/');
    }

    public static function normalizeSlug(string $slug): string
    {
        $candidate = trim(strtolower($slug));
        $candidate = str_replace(['/', '\\', '_'], '-', $candidate);
        $candidate = Str::slug($candidate);

        return trim($candidate, '-');
    }

    public static function forSlug(string $slug): string
    {
        $safeSlug = self::normalizeSlug($slug);

        return $safeSlug === '' ? '' : self::ACCOUNT_PREFIX.'/'.$safeSlug;
    }

    public static function slugFromPath(string $path): string
    {
        $candidate = self::normalize($path);
        $prefix = self::ACCOUNT_PREFIX.'/';

        if (str_starts_with($candidate, $prefix)) {
            return self::normalizeSlug(substr($candidate, strlen($prefix)));
        }

        return self::normalizeSlug($candidate);
    }

    public static function isReservedSlug(string $slug): bool
    {
        $candidate = self::normalizeSlug($slug);

        return $candidate === '' || in_array($candidate, self::RESERVED_PATHS, true);
    }

    public static function isReserved(string $path): bool
    {
        $candidate = self::normalize($path);

        return $candidate === ''
            || in_array($candidate, self::RESERVED_PATHS, true)
            || str_starts_with($candidate, 'admin/');
    }

    public static function defaultForTenant(int|string $tenantId, string $slug): string
    {
        $safeSlug = self::normalizeSlug($slug) ?: 'tenant';
        $hash = substr(hash('sha256', (string) $tenantId.'|'.$safeSlug), 0, 8);

        return self::ACCOUNT_PREFIX.'/'.$safeSlug.'-'.$hash;
    }
}

<?php

namespace App\Services\Domains;

use App\Models\TenantDomain;
use Illuminate\Support\Facades\Http;
use Throwable;

class RedirectVerificationService
{
    /**
     * @return array{ok: bool, status: string, code: ?int, checked_url: ?string, target: ?string, reason: ?string}
     */
    public function verifyRootRedirect(string $rootDomain, string $canonicalHostname): array
    {
        $root = $this->normalizeDomain($rootDomain);
        $canonical = $this->normalizeDomain($canonicalHostname);
        if ($root === '' || $canonical === '' || $root === $canonical) {
            return $this->result(false, TenantDomain::REDIRECT_STATUS_ACTION_REQUIRED, null, null, null, 'Root and canonical hostnames must be different.');
        }

        foreach (['https', 'http'] as $scheme) {
            $url = $scheme.'://'.$root.'/';
            try {
                $response = Http::timeout(8)->withoutRedirecting()->get($url);
            } catch (Throwable $exception) {
                continue;
            }

            $code = $response->status();
            $location = trim((string) $response->header('Location', ''));
            if (! in_array($code, [301, 302, 307, 308], true)) {
                continue;
            }

            $targetHost = $this->normalizeDomain($this->locationHost($location, $scheme, $root));
            if ($targetHost !== $canonical) {
                return $this->result(false, TenantDomain::REDIRECT_STATUS_FAILED, $code, $url, $location, 'Root domain redirects to the wrong hostname.');
            }

            if (in_array($code, [301, 308], true)) {
                return $this->result(true, TenantDomain::REDIRECT_STATUS_ACTIVE, $code, $url, $location, null);
            }

            return $this->result(false, TenantDomain::REDIRECT_STATUS_WARNING, $code, $url, $location, 'Temporary redirect detected. Use 301 or 308 for the root domain.');
        }

        return $this->result(false, TenantDomain::REDIRECT_STATUS_ACTION_REQUIRED, null, null, null, 'Root domain is not redirecting to the protected hostname.');
    }

    /**
     * @return array{ok: bool, status: string, code: ?int, checked_url: ?string, target: ?string, reason: ?string}
     */
    private function result(bool $ok, string $status, ?int $code, ?string $checkedUrl, ?string $target, ?string $reason): array
    {
        return [
            'ok' => $ok,
            'status' => $status,
            'code' => $code,
            'checked_url' => $checkedUrl,
            'target' => $target,
            'reason' => $reason,
        ];
    }

    private function locationHost(string $location, string $scheme, string $root): string
    {
        if ($location === '') {
            return '';
        }

        if (str_starts_with($location, '/')) {
            return $root;
        }

        $host = parse_url($location, PHP_URL_HOST);
        if (is_string($host) && $host !== '') {
            return $host;
        }

        $host = parse_url($scheme.'://'.$root.'/'.ltrim($location, '/'), PHP_URL_HOST);

        return is_string($host) ? $host : '';
    }

    private function normalizeDomain(string $domainName): string
    {
        $domain = strtolower(trim($domainName));
        $domain = preg_replace('#^https?://#', '', $domain) ?? $domain;
        $domain = explode('/', $domain, 2)[0];

        return rtrim(trim($domain), '.');
    }
}

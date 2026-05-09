<?php

namespace Tests\Unit;

use App\Services\Cloudflare\KVPurgeService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KVPurgeServiceTest extends TestCase
{
    public function test_key_generation_includes_runtime_and_legacy_variants_for_www_domain(): void
    {
        $keys = (new KVPurgeService)->keysForDomain('www.cashup.cash');

        $this->assertContains('cfg:www.cashup.cash', $keys);
        $this->assertContains('cfg:cashup.cash', $keys);
        $this->assertContains('dcfg:www.cashup.cash', $keys);
        $this->assertContains('dcfg:cashup.cash', $keys);
        $this->assertContains('cfr:www.cashup.cash', $keys);
        $this->assertContains('cfr:cashup.cash', $keys);
        $this->assertContains('cfr:sensitive_paths:www.cashup.cash', $keys);
        $this->assertContains('cfr:sensitive_paths:cashup.cash', $keys);
        $this->assertSame($keys, array_values(array_unique($keys)));
    }

    public function test_key_generation_includes_www_variant_for_apex_domain(): void
    {
        $keys = (new KVPurgeService)->keysForDomain('cashup.cash');

        $this->assertContains('cfg:cashup.cash', $keys);
        $this->assertContains('cfg:www.cashup.cash', $keys);
    }

    public function test_key_generation_includes_www_variant_for_multi_part_apex_domain(): void
    {
        $keys = (new KVPurgeService)->keysForDomain('example.co.uk');

        $this->assertContains('cfg:example.co.uk', $keys);
        $this->assertContains('cfg:www.example.co.uk', $keys);
    }

    public function test_key_generation_does_not_create_www_variant_for_subdomain(): void
    {
        $keys = (new KVPurgeService)->keysForDomain('ar.cashup.cash');

        $this->assertContains('cfg:ar.cashup.cash', $keys);
        $this->assertContains('cfr:sensitive_paths:ar.cashup.cash', $keys);
        $this->assertNotContains('cfg:www.ar.cashup.cash', $keys);
        $this->assertNotContains('cfr:www.ar.cashup.cash', $keys);
        $this->assertNotContains('cfr:sensitive_paths:www.ar.cashup.cash', $keys);
    }

    public function test_key_generation_does_not_strip_www_from_explicit_www_subdomain(): void
    {
        $keys = (new KVPurgeService)->keysForDomain('www.ar.cashup.cash');

        $this->assertContains('cfg:www.ar.cashup.cash', $keys);
        $this->assertNotContains('cfg:ar.cashup.cash', $keys);
    }

    public function test_purge_domain_deletes_each_key_through_cloudflare_rest_api(): void
    {
        Config::set('edgeshield.cloudflare_account_id', 'account-id');
        Config::set('edgeshield.runtime_kv_namespace_id', 'namespace-id');
        Config::set('edgeshield.cloudflare_api_token', 'secret-token');
        Http::fake([
            'https://api.cloudflare.com/client/v4/accounts/*' => Http::response(['success' => true], 200),
        ]);

        $result = (new KVPurgeService)->purgeDomain('cashup.cash');

        $this->assertTrue($result['ok']);
        Http::assertSent(function ($request): bool {
            return $request->method() === 'DELETE'
                && str_contains($request->url(), '/accounts/account-id/storage/kv/namespaces/namespace-id/values/')
                && $request->hasHeader('Authorization', 'Bearer secret-token');
        });
    }

    public function test_purge_domain_reports_partial_cloudflare_failures_without_exposing_token(): void
    {
        Config::set('edgeshield.cloudflare_account_id', 'account-id');
        Config::set('edgeshield.runtime_kv_namespace_id', 'namespace-id');
        Config::set('edgeshield.cloudflare_api_token', 'secret-token');
        Http::fake([
            '*' => Http::response(['success' => false, 'errors' => [['message' => 'boom']]], 500),
        ]);

        $result = (new KVPurgeService)->purgeDomain('cashup.cash');

        $this->assertFalse($result['ok']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringNotContainsString('secret-token', implode(' ', $result['errors']));
    }

    public function test_purge_domain_treats_missing_keys_as_success(): void
    {
        Config::set('edgeshield.cloudflare_account_id', 'account-id');
        Config::set('edgeshield.runtime_kv_namespace_id', 'namespace-id');
        Config::set('edgeshield.cloudflare_api_token', 'secret-token');
        Http::fake([
            '*' => Http::response(['success' => false, 'errors' => [['message' => 'not found']]], 404),
        ]);

        $result = (new KVPurgeService)->purgeDomain('cashup.cash');

        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['errors']);
    }

    public function test_purge_domain_is_blocked_for_production_readonly_target(): void
    {
        Config::set('edgeshield.target_env', 'production_readonly');
        Http::fake();

        $result = (new KVPurgeService)->purgeDomain('cashup.cash');

        $this->assertFalse($result['ok']);
        $this->assertSame(['Production is read-only from local dashboard.'], $result['errors']);
        Http::assertNothingSent();
    }
}

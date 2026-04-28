<?php

namespace Tests\Unit;

use App\Models\TenantDomain;
use App\Services\Domains\RedirectVerificationService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RedirectVerificationServiceTest extends TestCase
{
    public function test_permanent_redirect_is_active(): void
    {
        Http::fake([
            'https://example.com/' => Http::response('', 301, ['Location' => 'https://www.example.com/']),
        ]);

        $result = (new RedirectVerificationService)->verifyRootRedirect('example.com', 'www.example.com');

        $this->assertTrue($result['ok']);
        $this->assertSame(TenantDomain::REDIRECT_STATUS_ACTIVE, $result['status']);
    }

    public function test_temporary_redirect_is_warning(): void
    {
        Http::fake([
            'https://example.com/' => Http::response('', 302, ['Location' => 'https://www.example.com/']),
        ]);

        $result = (new RedirectVerificationService)->verifyRootRedirect('example.com', 'www.example.com');

        $this->assertFalse($result['ok']);
        $this->assertSame(TenantDomain::REDIRECT_STATUS_WARNING, $result['status']);
    }
}

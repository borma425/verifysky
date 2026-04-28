<?php

namespace App\Services\Domains {
    if (! function_exists(__NAMESPACE__.'\\dns_get_record')) {
        function dns_get_record(string $hostname, int $type): array|false
        {
            return $GLOBALS['verifysky_test_dns_records'][$hostname][$type] ?? [];
        }
    }
}

namespace Tests\Unit {
    use App\Services\Domains\DnsVerificationService;
    use PHPUnit\Framework\TestCase;

    class DnsVerificationServiceTest extends TestCase
    {
        protected function tearDown(): void
        {
            unset($GLOBALS['verifysky_test_dns_records']);

            parent::tearDown();
        }

        public function test_www_redirect_mode_returns_www_hostname_only(): void
        {
            $service = new DnsVerificationService;

            $this->assertSame(['www.example.com'], $service->hostnamesForInput('example.com', 'www_redirect'));
        }

        public function test_direct_apex_mode_returns_root_and_www_hostnames(): void
        {
            $service = new DnsVerificationService;

            $this->assertSame(['example.com', 'www.example.com'], $service->hostnamesForInput('example.com', 'direct_apex'));
        }

        public function test_subdomain_only_mode_preserves_input_hostname(): void
        {
            $service = new DnsVerificationService;

            $this->assertSame(['app.example.com'], $service->hostnamesForInput('app.example.com', 'www_redirect'));
        }

        public function test_flattened_apex_ip_records_can_be_accepted_for_direct_apex(): void
        {
            $GLOBALS['verifysky_test_dns_records'] = [
                'example.com' => [
                    DNS_A => [['ip' => '203.0.113.10']],
                ],
            ];

            $result = (new DnsVerificationService)->verifyManagedHostname('example.com', 'customers.verifysky.com', true);

            $this->assertTrue($result['ok']);
            $this->assertTrue($result['flattened_apex']);
        }
    }
}

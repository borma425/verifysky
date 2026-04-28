<?php

namespace App\Services\EdgeShield\Concerns {
    function dns_get_record(string $hostname, int $type): array|false
    {
        return $GLOBALS['verifysky_test_dns_records'][$hostname][$type] ?? [];
    }
}

namespace Tests\Unit {
    use App\Services\EdgeShield\Concerns\SaasHostnameLifecycleConcern;
    use PHPUnit\Framework\TestCase;

    class SaasHostnameLifecycleConcernTest extends TestCase
    {
        protected function tearDown(): void
        {
            unset($GLOBALS['verifysky_test_dns_records']);

            parent::tearDown();
        }

        public function test_dns_route_set_checks_only_the_managed_hostname(): void
        {
            $GLOBALS['verifysky_test_dns_records'] = [
                'www.example.com' => [
                    DNS_CNAME => [['target' => 'customers.verifysky.com']],
                ],
                'example.com' => [
                    DNS_A => [['ip' => '203.0.113.10']],
                ],
            ];

            $service = new class
            {
                use SaasHostnameLifecycleConcern;
            };

            $result = $service->verifySaasDnsRouteSet('www.example.com', 'customers.verifysky.com');

            $this->assertTrue($result['ok']);
            $this->assertArrayHasKey('www.example.com', $result['checks']);
            $this->assertArrayNotHasKey('example.com', $result['checks']);
        }
    }
}

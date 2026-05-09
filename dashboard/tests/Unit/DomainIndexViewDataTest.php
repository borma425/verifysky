<?php

namespace Tests\Unit;

use App\ViewData\DomainIndexViewData;
use Tests\TestCase;

class DomainIndexViewDataTest extends TestCase
{
    public function test_subdomain_dns_record_name_uses_leftmost_label_not_apex(): void
    {
        $viewData = new DomainIndexViewData([
            'ok' => true,
            'domains' => [[
                'domain_name' => 'ar.cashup.cash',
                'cname_target' => 'customers.verifysky.com',
                'hostname_status' => 'pending',
                'ssl_status' => 'pending_validation',
                'provisioning_status' => 'provisioning',
            ]],
        ], 'customers.verifysky.com');

        $group = $viewData->toArray()['preparedDomainGroups'][0];

        $this->assertSame('ar.cashup.cash', $group['primary_domain']);
        $this->assertSame('ar', $group['dns_rows'][0]['record_name']);
        $this->assertSame('customers.verifysky.com', $group['dns_rows'][0]['target']);
    }

    public function test_apex_dns_record_name_still_uses_at_symbol(): void
    {
        $viewData = new DomainIndexViewData([
            'ok' => true,
            'domains' => [[
                'domain_name' => 'cashup.cash',
                'cname_target' => 'customers.verifysky.com',
                'hostname_status' => 'pending',
                'ssl_status' => 'pending_validation',
                'provisioning_status' => 'provisioning',
            ]],
        ], 'customers.verifysky.com');

        $group = $viewData->toArray()['preparedDomainGroups'][0];

        $this->assertSame('@', $group['dns_rows'][0]['record_name']);
    }
}

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

    public function test_group_runtime_status_uses_primary_protected_hostname(): void
    {
        $viewData = new DomainIndexViewData([
            'ok' => true,
            'domains' => [
                [
                    'domain_name' => 'cashup.cash',
                    'status' => 'paused',
                    'cname_target' => 'customers.verifysky.com',
                    'hostname_status' => 'active',
                    'ssl_status' => 'active',
                    'provisioning_status' => 'active',
                ],
                [
                    'domain_name' => 'www.cashup.cash',
                    'status' => 'active',
                    'cname_target' => 'customers.verifysky.com',
                    'hostname_status' => 'active',
                    'ssl_status' => 'active',
                    'provisioning_status' => 'active',
                ],
            ],
        ], 'customers.verifysky.com');

        $group = $viewData->toArray()['preparedDomainGroups'][0];

        $this->assertSame('www.cashup.cash', $group['primary_domain']);
        $this->assertSame('active', $group['status']);
        $this->assertTrue($group['primary_verified']);
    }

    public function test_invalid_legacy_runtime_status_is_treated_as_enabled_not_disabled(): void
    {
        $viewData = new DomainIndexViewData([
            'ok' => true,
            'domains' => [[
                'domain_name' => 'www.cashup.cash',
                'status' => 'pending',
                'cname_target' => 'customers.verifysky.com',
                'hostname_status' => 'pending',
                'ssl_status' => 'pending_validation',
                'provisioning_status' => 'active',
            ]],
        ], 'customers.verifysky.com');

        $group = $viewData->toArray()['preparedDomainGroups'][0];

        $this->assertSame('active', $group['status']);
        $this->assertFalse($group['primary_verified']);
    }
}

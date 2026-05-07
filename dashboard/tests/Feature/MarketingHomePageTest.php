<?php

namespace Tests\Feature;

use Tests\TestCase;

class MarketingHomePageTest extends TestCase
{
    public function test_homepage_uses_worker_driven_marketing_design(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('VerifySky — Ad Protection & Edge Cybersecurity Platform', false);
        $response->assertSee('Stop Fake Clicks Before');
        $response->assertSee('They Drain Your Budget');
        $response->assertSee('LIVE REQUEST TELEMETRY');
        $response->assertSee('Request Ingestion');
        $response->assertSee('WAF + AI Analysis');
        $response->assertSee('Threat Decision');
        $response->assertSee('Clean Traffic');
        $response->assertSee('Worker Intelligence');
        $response->assertSee('Live Decision Engine');
        $response->assertSee('Enterprise-Grade Protection');
        $response->assertSee('Ready to secure your ad spend?');
        $response->assertSee('Now with Google Ads & AdSense Integration', false);
        $response->assertSee('Ad Click Spoofing Detection');
        $response->assertSee('Multi-Signal Risk Scoring');
        $response->assertSee('IP/Subnet/ASN Swarm Detection');
        $response->assertSee('Dynamic Honeypot Decoys');
        $response->assertSee('Slider CAPTCHA + Human Telemetry');
        $response->assertSee('Signed Human Sessions');
        $response->assertSee('AI WAF Rule Automation');
        $response->assertSee('Blocked IP List');
        $response->assertSee('Protected Session Metering');
        $response->assertSee('PASS');
        $response->assertSee('CHALLENGE');
        $response->assertSee('BLOCK');
        $response->assertSee('AUTO_RULE');
        $response->assertSee('BLOCKED_IP');
        $response->assertSee(asset('duotone/user-ninja.svg'), false);
        $response->assertSee(asset('duotone/brain-circuit.svg'), false);
        $response->assertSee(asset('duotone/radar.svg'), false);
        $response->assertSee(asset('duotone/server.svg'), false);
        $response->assertSee(asset('duotone/cloud-check.svg'), false);
        $response->assertSee('progress-indicator');
        $response->assertSee('log-line');
        $response->assertSee('EDGE SHIELD');
        $response->assertSee('SERVER');
        $response->assertSee('Create Account');
        $response->assertSee('Start Defending');
        $response->assertSee('Explore Platform');
        $response->assertSee('Free');
        $response->assertSee('Starter');
        $response->assertSee('$9');
        $response->assertSee('Pro');
        $response->assertSee('Business');
        $response->assertSee('Scale');
        $response->assertSee(route('register'), false);
        $response->assertSee('https://cdn.tailwindcss.com');
        $response->assertDontSee('Logo.png');
        $response->assertDontSee('Core Superpowers');
        $response->assertDontSee('ES-Topology');
        $response->assertDontSee('Explore Architecture');
        $response->assertDontSee('timeline.network');
        $response->assertDontSee('Open Control Plane');
        $response->assertDontSee('admin.login');
        $response->assertDontSee('Sign in');
    }
}

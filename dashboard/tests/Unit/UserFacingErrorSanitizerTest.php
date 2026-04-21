<?php

namespace Tests\Unit;

use App\Support\UserFacingErrorSanitizer;
use PHPUnit\Framework\TestCase;

class UserFacingErrorSanitizerTest extends TestCase
{
    public function test_it_replaces_sensitive_runtime_errors_with_a_generic_message(): void
    {
        $raw = "\e[31m✘ [ERROR]\e[0m no such column: tenant_id at offset 76: SQLITE_ERROR 🪵 Logs were written to \"/opt/lampp/htdocs/verifysky/dashboard/storage/wrangler-runtime/logs/wrangler-2026-04-21_15-16-50_785.log\"";

        $sanitized = UserFacingErrorSanitizer::sanitize($raw);

        $this->assertSame(UserFacingErrorSanitizer::defaultMessage(), $sanitized);
    }

    public function test_it_keeps_user_facing_messages_when_they_are_not_sensitive(): void
    {
        $message = 'Billing migrations are pending. Run the billing migrations first.';

        $sanitized = UserFacingErrorSanitizer::sanitize($message);

        $this->assertSame($message, $sanitized);
    }
}

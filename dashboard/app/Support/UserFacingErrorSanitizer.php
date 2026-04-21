<?php

namespace App\Support;

final class UserFacingErrorSanitizer
{
    public static function defaultMessage(): string
    {
        return 'Unable to complete this action right now. Please try again.';
    }

    public static function sanitize(?string $message, ?string $fallback = null): string
    {
        $fallbackMessage = trim((string) ($fallback ?? self::defaultMessage()));
        $normalized = self::normalize($message);

        if ($normalized === '') {
            return $fallbackMessage;
        }

        if (self::containsSensitiveDetails($normalized)) {
            return $fallbackMessage;
        }

        return mb_strimwidth($normalized, 0, 280, '...');
    }

    private static function normalize(?string $message): string
    {
        $normalized = (string) ($message ?? '');
        if ($normalized === '') {
            return '';
        }

        // Strip ANSI escape/control sequences emitted by CLI tools.
        $normalized = preg_replace('/\x1B(?:[@-Z\\\\-_]|\[[0-?]*[ -\\/]*[@-~])/', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;

        return trim($normalized);
    }

    private static function containsSensitiveDetails(string $message): bool
    {
        $patterns = [
            '/\bSQLITE_ERROR\b/i',
            '/\bSQLSTATE\[[^\]]+\]/i',
            '/\bno such (?:column|table)\b/i',
            '/\boffset\s+\d+\b/i',
            '/\bstack trace\b/i',
            '/\btrace:\b/i',
            '/\b(?:PDOException|RuntimeException|ErrorException)\b/i',
            '/\bSymfony\\\\Component\\\\Process\\\\/i',
            '/\bLogs were written to\b/i',
            '/wrangler-runtime/i',
            '/wrangler-\d{4}-\d{2}-\d{2}_[\d\-_]+\.log/i',
            '#/opt/lampp/[^\\s"]+#i',
            '/\[(?:ERROR|FATAL)\]/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message) === 1) {
                return true;
            }
        }

        return false;
    }
}

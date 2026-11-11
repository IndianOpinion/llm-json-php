<?php

declare(strict_types=1);

namespace IndianOpinion\LlmJson\Internal;

/**
 * Cuts error bodies to an excerpt and removes anything shaped like a key.
 *
 * @internal
 */
final class Redactor
{
    private const PATTERNS = [
        '/\bBearer\s+[A-Za-z0-9._~+\/=\-]+/i' => 'Bearer [redacted]',
        '/\b(?:sk|pk|rk)-[A-Za-z0-9_\-]{8,}/' => '[redacted key]',
        '/\bAIza[0-9A-Za-z_\-]{20,}/' => '[redacted key]',
        '/("(?:api[_-]?key|authorization|token|secret)"\s*:\s*")[^"]*(")/i' => '$1[redacted]$2',
        '/\b((?:api[_-]?key|key|token|secret)=)[^&\s"\']+/i' => '$1[redacted]',
    ];

    /**
     * @param list<string> $secrets literal values to remove as well (the provider key)
     */
    public static function excerpt(string $body, int $max, array $secrets = []): string
    {
        $body = trim($body);
        foreach ($secrets as $secret) {
            if ($secret !== '') {
                $body = str_replace($secret, '[redacted key]', $body);
            }
        }
        foreach (self::PATTERNS as $pattern => $replacement) {
            $body = (string) preg_replace($pattern, $replacement, $body);
        }

        return Text::cut($body, $max);
    }
}

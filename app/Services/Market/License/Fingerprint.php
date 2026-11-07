<?php

namespace App\Services\Market\License;

/**
 * Stable per-site fingerprint: HMAC-SHA256 over the request host, keyed by
 * the application key. Sent to the market on activation; the platform uses
 * it to enforce `max_sites` without ever seeing the raw hostname mapping.
 */
final class Fingerprint
{
    /**
     * Fingerprint of an explicit host (usable from CLI and tests).
     */
    public static function forHost(string $host): string
    {
        return hash_hmac('sha256', $host, (string) config('app.key'));
    }

    /**
     * Fingerprint of the current HTTP request host.
     */
    public static function current(): string
    {
        $host = request()?->getHost();

        if (! is_string($host) || $host === '') {
            throw new LicenseException('当前上下文没有站点 Host，无法计算站点指纹（激活必须在 HTTP 上下文或显式传入 host）。');
        }

        return self::forHost($host);
    }
}

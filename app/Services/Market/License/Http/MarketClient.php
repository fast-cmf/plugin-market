<?php

namespace App\Services\Market\License\Http;

use App\Services\Market\License\LicenseException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * HTTP client for the market platform's license endpoints (design §3.5).
 *
 * - `activate` doubles as the daily heartbeat: it is idempotent per site
 *   fingerprint and returns the (possibly re-signed) document.
 * - `revocations` returns the platform's revocation list; a failure here is
 *   never fatal (fail-open), callers treat it as "cannot judge this round".
 */
final class MarketClient
{
    /**
     * Activate (or heartbeat) a license for a site fingerprint.
     *
     * @return array{state: string, document: array<string, mixed>}
     *
     * @throws LicenseException On network failure, non-2xx responses, or malformed payloads.
     */
    public function activate(string $licenseKey, string $fingerprint): array
    {
        try {
            $response = Http::acceptJson()
                ->timeout(10)
                ->withToken($licenseKey)
                ->post($this->url('licenses/activate'), ['fingerprint' => $fingerprint]);
        } catch (ConnectionException $e) {
            throw new LicenseException('市场平台连接失败，无法完成激活/心跳：'.$e->getMessage(), 0, $e);
        }

        if ($response->status() === 401) {
            throw new LicenseException('License Key 无效或不存在。', 401);
        }

        if ($response->status() === 403) {
            throw new LicenseException('License Key 已被吊销或与产品不匹配。', 403);
        }

        if ($response->status() === 409) {
            throw new LicenseException('站点激活数已达上限，请先在其他站点执行 market:deactivate。', 409);
        }

        if (! $response->successful()) {
            throw new LicenseException(sprintf('激活请求失败（HTTP %d）：%s', $response->status(), (string) $response->body()), $response->status());
        }

        $payload = $response->json('data');

        if (! is_array($payload) || ! is_array($payload['document'] ?? null) || ! is_string($payload['state'] ?? null)) {
            throw new LicenseException('激活响应格式不合法。');
        }

        return [
            'state' => $payload['state'],
            'document' => $payload['document'],
        ];
    }

    /**
     * Fetch the platform revocation list (license keys).
     *
     * @return list<string>
     *
     * @throws LicenseException On network failure or non-2xx responses.
     */
    public function revocations(): array
    {
        try {
            $response = Http::acceptJson()
                ->timeout(10)
                ->get($this->url('security/revocations'));
        } catch (ConnectionException $e) {
            throw new LicenseException('市场平台连接失败，无法获取吊销列表：'.$e->getMessage(), 0, $e);
        }

        if (! $response->successful()) {
            throw new LicenseException(sprintf('吊销列表请求失败（HTTP %d）。', $response->status()), $response->status());
        }

        $revoked = $response->json('data.revoked');

        return is_array($revoked) ? array_values(array_map(strval(...), $revoked)) : [];
    }

    private function url(string $path): string
    {
        return rtrim((string) config('plugin-market.license.base_url'), '/').'/'.ltrim($path, '/');
    }
}

<?php

namespace App\Services\Market\License;

use Openframe\PluginMarketSdk\Signature\PackageSigner;
use Openframe\PluginMarketSdk\Signature\SigningKey;
use SodiumException;

/**
 * Immutable view over a platform-signed license document (design §4.2).
 *
 * The document is verified against the platform public key on every
 * instantiation — verification is purely local (zero network calls), and a
 * tampered or malformed document can never expose authorization semantics.
 */
final class LicenseDocument
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    private function __construct(private readonly array $attributes) {}

    /**
     * Verify a raw document (as stored locally or returned by the platform)
     * against the platform public key and return an immutable instance.
     *
     * @param  array<string, mixed>  $document
     *
     * @throws LicenseException On missing fields, bad encodings, or signature mismatch.
     */
    public static function verify(array $document, string $platformPublicKeyBase64): self
    {
        foreach (['lic', 'product', 'expires_at', 'grace_days', 'features', 'sig'] as $field) {
            if (! array_key_exists($field, $document)) {
                throw new LicenseException(sprintf('License 凭证缺少必填字段 "%s"。', $field));
            }
        }

        if (! is_string($document['lic']) || $document['lic'] === ''
            || ! is_string($document['product']) || $document['product'] === ''
            || ! is_string($document['sig']) || $document['sig'] === '') {
            throw new LicenseException('License 凭证字段类型不合法。');
        }

        if (! is_int($document['expires_at']) || ! is_int($document['grace_days'])) {
            throw new LicenseException('License 凭证的 expires_at / grace_days 必须是整数时间戳。');
        }

        if (! is_array($document['features'])) {
            throw new LicenseException('License 凭证的 features 必须是数组。');
        }

        $publicKey = base64_decode($platformPublicKeyBase64, true);

        if ($publicKey === false || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new LicenseException('平台公钥未配置或不合法（MARKET_PLATFORM_PUBLIC_KEY）。');
        }

        $signature = base64_decode((string) $document['sig'], true);

        if ($signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw new LicenseException('License 凭证签名编码不合法。');
        }

        $payload = PackageSigner::canonicalJson(
            collect($document)->except('sig')->all(),
        );

        try {
            $valid = sodium_crypto_sign_verify_detached($signature, $payload, $publicKey);
        } catch (SodiumException) {
            $valid = false;
        }

        if (! $valid) {
            throw new LicenseException('License 凭证签名验证失败，凭证可能已被篡改。');
        }

        return new self($document);
    }

    /**
     * Sign a document with the given key — only meaningful in tests and
     * local tooling that mimics the platform issuer.
     *
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    public static function signForTesting(array $document, SigningKey $platformKey): array
    {
        unset($document['sig']);

        $document['sig'] = base64_encode(
            sodium_crypto_sign_detached(
                PackageSigner::canonicalJson($document),
                $platformKey->secretKey(),
            ),
        );

        return $document;
    }

    public function key(): string
    {
        return (string) $this->attributes['lic'];
    }

    public function product(): string
    {
        return (string) $this->attributes['product'];
    }

    /**
     * @return list<string> Site fingerprints bound to this license.
     */
    public function sites(): array
    {
        $sites = $this->attributes['sites'] ?? [];

        return is_array($sites) ? array_values(array_map(strval(...), $sites)) : [];
    }

    public function maxSites(): int
    {
        return (int) ($this->attributes['max_sites'] ?? 0);
    }

    public function issuedAt(): int
    {
        return (int) ($this->attributes['issued_at'] ?? 0);
    }

    public function expiresAt(): int
    {
        return (int) $this->attributes['expires_at'];
    }

    /**
     * Grace period in days. The signed value wins; the local config value is
     * only a fallback for documents issued without one.
     */
    public function graceDays(): int
    {
        $days = (int) $this->attributes['grace_days'];

        return $days > 0
            ? $days
            : (int) config('plugin-market.license.grace_days', 14);
    }

    /**
     * @return list<string>
     */
    public function features(): array
    {
        return array_values(array_map(strval(...), (array) $this->attributes['features']));
    }

    public function hasFeature(string $feature): bool
    {
        return in_array($feature, $this->features(), true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->attributes;
    }
}

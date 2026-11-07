<?php

namespace App\Services\Market\License;

use Illuminate\Support\Facades\Crypt;

/**
 * Encrypted-at-rest persistence for the license payload (design §4.4).
 *
 * The archive holds the signed document plus the anti-rollback anchor
 * (`last_seen_at`), the last successful heartbeat, the local site
 * fingerprint, and the revocation flag. Everything is encrypted with the
 * application key so the license key and authorization semantics are not
 * readable or editable in place.
 */
final class LicenseStore
{
    /**
     * @return array{document: array<string, mixed>, last_seen_at: int, last_heartbeat_at: int, fingerprint: ?string, revoked: bool}|null
     */
    public function load(): ?array
    {
        $path = $this->path();

        if (! is_file($path)) {
            return null;
        }

        $raw = (string) file_get_contents($path);

        try {
            $payload = json_decode(Crypt::decryptString($raw), true);
        } catch (\Throwable) {
            return null;
        }

        return is_array($payload) ? $payload : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function put(array $payload): void
    {
        $path = $this->path();
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($path, Crypt::encryptString((string) json_encode($payload)));
    }

    public function forget(): void
    {
        $path = $this->path();

        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function path(): string
    {
        return (string) config('plugin-market.license.store_path', storage_path('app/market/license.json'));
    }
}

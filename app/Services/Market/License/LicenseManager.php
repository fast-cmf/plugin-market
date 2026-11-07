<?php

namespace App\Services\Market\License;

use App\Services\Market\License\Http\MarketClient;

/**
 * License lifecycle core (design §4.5).
 *
 * State machine: inactive --activate--> active --(expired / disconnected)-->
 * grace --(timeout)--> expired; revocation wins over everything. All checks
 * are local (encrypted archive + offline signature verification); the network
 * is only touched by activate() and the daily heartbeat(), both of which
 * fail open — a broken market platform must never take a healthy site down.
 *
 * Anti-rollback: `last_seen_at` only ever increases; a clock rolled back
 * behind it resumes judgement from the anchor instead of resurrecting a
 * dead license.
 */
final class LicenseManager
{
    public function __construct(
        private readonly LicenseStore $store,
        private readonly MarketClient $client,
        private readonly string $platformPublicKey,
    ) {}

    /**
     * Scoped view for one product (facade entry: License::for($product)).
     */
    public function for(string $product): LicenseScope
    {
        return new LicenseScope($this, $product);
    }

    /**
     * Activate (or heartbeat) a license key for this site.
     *
     * @throws LicenseException When the platform rejects the key, the site
     *                          quota is exhausted, or the returned document
     *                          fails offline verification.
     */
    public function activate(string $licenseKey, ?string $fingerprint = null): LicenseDocument
    {
        $fingerprint ??= Fingerprint::current();

        $response = $this->client->activate($licenseKey, $fingerprint);
        $document = LicenseDocument::verify($response['document'], $this->platformPublicKey);

        $payload = $this->store->load() ?? [];
        $payload['document'] = $document->toArray();
        $payload['fingerprint'] = $fingerprint;
        $payload['last_heartbeat_at'] = time();
        $payload['revoked'] = $response['state'] === 'revoked';
        $payload['last_seen_at'] = max((int) ($payload['last_seen_at'] ?? 0), time());
        $this->store->put($payload);

        return $document;
    }

    /**
     * Drop the local license archive (frees the site slot on the next
     * activation elsewhere; platform-side release is a V2 concern).
     */
    public function deactivate(): void
    {
        $this->store->forget();
    }

    /**
     * Locally stored document, re-verified offline on every read. A tampered
     * archive reads as "no license" rather than throwing.
     */
    public function document(): ?LicenseDocument
    {
        $payload = $this->store->load();

        if (! is_array($payload['document'] ?? null)) {
            return null;
        }

        try {
            return LicenseDocument::verify($payload['document'], $this->platformPublicKey);
        } catch (LicenseException) {
            return null;
        }
    }

    /**
     * Global (unscoped) state: inactive|active|grace|expired|revoked.
     */
    public function state(): string
    {
        $payload = $this->store->load();

        if ($payload === null) {
            return 'inactive';
        }

        if ($payload['revoked'] ?? false) {
            return 'revoked';
        }

        $document = $this->document();

        if ($document === null) {
            return 'inactive';
        }

        // Anti-rollback anchor: now() can never fall behind last_seen_at.
        $seen = max((int) ($payload['last_seen_at'] ?? 0), time());

        if ($seen !== (int) ($payload['last_seen_at'] ?? 0)) {
            $payload['last_seen_at'] = $seen;
            $this->store->put($payload);
        }

        $graceSeconds = $document->graceDays() * 86400;

        return match (true) {
            $seen < $document->expiresAt() => 'active',
            $seen < $document->expiresAt() + $graceSeconds => 'grace',
            default => 'expired',
        };
    }

    /**
     * Daily heartbeat, scheduled silently (routes/console.php). Re-signs the
     * document, refreshes the revocation flag, and always fails open.
     */
    public function heartbeat(): void
    {
        $document = $this->document();

        if ($document === null) {
            return;
        }

        $payload = $this->store->load() ?? [];
        $fingerprint = $payload['fingerprint'] ?? null;

        if (! is_string($fingerprint) || $fingerprint === '') {
            return;
        }

        try {
            $response = $this->client->activate($document->key(), $fingerprint);
            $fresh = LicenseDocument::verify($response['document'], $this->platformPublicKey);

            try {
                $revoked = in_array($fresh->key(), $this->client->revocations(), true);
            } catch (LicenseException) {
                $revoked = (bool) ($payload['revoked'] ?? false);
            }

            $payload['document'] = $fresh->toArray();
            $payload['last_heartbeat_at'] = time();
            $payload['revoked'] = $revoked;
            $payload['last_seen_at'] = max((int) ($payload['last_seen_at'] ?? 0), time());
            $this->store->put($payload);
        } catch (LicenseException) {
            // Fail-open: keep judging from the local archive until grace runs out.
        }
    }
}

<?php

namespace App\Services\Market\License;

/**
 * Product-scoped view over the license manager, powering the public facade:
 *
 *     License::for('todo-pro')->isValid()
 *     License::for('todo-pro')->feature('export')
 *     License::for('todo-pro')->state()
 *
 * A stored document for a different product is indistinguishable from "no
 * license" within this scope.
 */
final class LicenseScope
{
    public function __construct(
        private readonly LicenseManager $manager,
        private readonly string $product,
    ) {}

    /**
     * Local state of this product's license: inactive|active|grace|expired|revoked.
     */
    public function state(): string
    {
        $document = $this->manager->document();

        if ($document === null || $document->product() !== $this->product) {
            return 'inactive';
        }

        return $this->manager->state();
    }

    /**
     * Usable right now: active or within the grace period. Expired and
     * revoked licenses degrade to read-only — they never throw.
     */
    public function isValid(): bool
    {
        return in_array($this->state(), ['active', 'grace'], true);
    }

    public function feature(string $feature): bool
    {
        $document = $this->manager->document();

        if ($document === null || $document->product() !== $this->product) {
            return false;
        }

        return $this->isValid() && $document->hasFeature($feature);
    }

    public function document(): ?LicenseDocument
    {
        $document = $this->manager->document();

        return ($document !== null && $document->product() === $this->product) ? $document : null;
    }
}

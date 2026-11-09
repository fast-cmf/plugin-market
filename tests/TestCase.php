<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Existing tests assume a fully installed application; installer
        // tests override installer.lock_path to exercise the wizard.
        $lock = storage_path('framework/testing/installer.lock');

        if (! is_dir(dirname($lock))) {
            @mkdir((string) dirname($lock), 0777, true);
        }

        if (! is_file($lock)) {
            file_put_contents($lock, 'installed for tests');
        }

        config(['installer.lock_path' => $lock]);
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}

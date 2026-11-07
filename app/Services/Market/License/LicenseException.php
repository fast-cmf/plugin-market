<?php

namespace App\Services\Market\License;

use RuntimeException;

/**
 * Domain exception for the license verification flow.
 *
 * Thrown on cryptographic failures (tampered documents), activation
 * rejections from the market platform, and unusable local state.
 */
class LicenseException extends RuntimeException {}

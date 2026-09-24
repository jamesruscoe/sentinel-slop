<?php

declare(strict_types=1);

namespace App\Scanning\Exceptions;

/**
 * A scan whose worker went away mid-pipeline (deployment, restart, crash).
 * Its workspace lived on that worker's disk, so it cannot resume anywhere.
 */
final class ScanInterruptedException extends ScanException {}

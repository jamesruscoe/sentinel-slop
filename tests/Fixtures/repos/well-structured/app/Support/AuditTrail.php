<?php

namespace App\Support;

use Psr\Log\LoggerInterface;

/**
 * Thin wrapper around the PSR logger so services log business events consistently.
 * Deliberately not named "Logger": the profile must recognise it by what it references.
 */
final class AuditTrail
{
    public function __construct(private readonly LoggerInterface $logger) {}

    public function record(string $event, array $context = []): void
    {
        $this->logger->info($event, $context);
    }

    public function failure(string $event, \Throwable $e): void
    {
        $this->logger->error($event, ['exception' => $e->getMessage()]);
    }
}
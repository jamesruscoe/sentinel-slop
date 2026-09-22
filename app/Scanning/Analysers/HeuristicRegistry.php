<?php

declare(strict_types=1);

namespace App\Scanning\Analysers;

/**
 * Same shape as AnalyserRegistry; a distinct class so the container can bind
 * the analyser set and the heuristic set independently.
 */
final class HeuristicRegistry extends AnalyserRegistryBase {}

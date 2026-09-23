<?php

declare(strict_types=1);

namespace App\Scanning\Enums;

enum FindingCategory: string
{
    case Malware = 'malware';
    case Secrets = 'secrets';
    case Security = 'security';
    case HallucinatedDependency = 'hallucinated_dependency';
    case DeadCode = 'dead_code';
    case Duplication = 'duplication';
    case ErrorHandling = 'error_handling';
    case Placeholder = 'placeholder';
    case Complexity = 'complexity';
    case Testing = 'testing';
    case TypeSafety = 'type_safety';
    case Style = 'style';
    case Slop = 'slop';
    case Structure = 'structure';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Malware => 'Malware / backdoor',
            self::Secrets => 'Secrets',
            self::Security => 'Security',
            self::HallucinatedDependency => 'Hallucinated dependency',
            self::DeadCode => 'Dead code',
            self::Duplication => 'Duplication',
            self::ErrorHandling => 'Error handling',
            self::Placeholder => 'Placeholder / stub',
            self::Complexity => 'Complexity',
            self::Testing => 'Testing',
            self::TypeSafety => 'Type safety',
            self::Style => 'Style',
            self::Slop => 'AI slop',
            self::Structure => 'Structure / absence',
            self::Other => 'Other',
        };
    }

    /**
     * Categories that count as security-critical for score capping and UI emphasis.
     */
    public function isSecurityCritical(): bool
    {
        return in_array($this, [self::Malware, self::Secrets], true);
    }
}

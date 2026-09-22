<?php

declare(strict_types=1);

namespace App\Scanning\Enums;

enum SkipReason: string
{
    case Symlink = 'symlink';
    case Submodule = 'submodule';
    case Binary = 'binary';
    case Executable = 'executable';
    case DependencyDirectory = 'dependency_directory';
    case Generated = 'generated';
    case Minified = 'minified';
    case Oversized = 'oversized';
    case PathEscape = 'path_escape';
    case UnsupportedMode = 'unsupported_mode';
    case NameCollision = 'name_collision';

    public function label(): string
    {
        return match ($this) {
            self::Symlink => 'Symbolic link',
            self::Submodule => 'Git submodule',
            self::Binary => 'Binary file',
            self::Executable => 'Executable file',
            self::DependencyDirectory => 'Dependency or build directory',
            self::Generated => 'Generated file',
            self::Minified => 'Minified file',
            self::Oversized => 'Exceeds single-file size limit',
            self::PathEscape => 'Path escapes the scan directory',
            self::UnsupportedMode => 'Unsupported git object mode',
            self::NameCollision => 'Filename collides with another on a case-insensitive filesystem',
        };
    }
}

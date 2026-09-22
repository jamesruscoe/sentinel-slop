<?php

declare(strict_types=1);

namespace App\Scanning\Heuristics;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * Parses PHP files for the heuristics. Parse only, never evaluate: the AST is
 * inspected and thrown away. Files that fail to parse are skipped.
 */
final class PhpSource
{
    private static ?Parser $parser = null;

    /**
     * @return array<Node\Stmt>|null
     */
    public static function parse(string $absolutePath): ?array
    {
        $code = @file_get_contents($absolutePath);
        if ($code === false || $code === '') {
            return null;
        }

        try {
            return self::parser()->parse($code);
        } catch (Error) {
            return null;
        }
    }

    /**
     * @template T of Node
     *
     * @param  array<Node>  $ast
     * @param  class-string<T>  $class
     * @return list<T>
     */
    public static function find(array $ast, string $class): array
    {
        return array_values((new NodeFinder)->findInstanceOf($ast, $class));
    }

    private static function parser(): Parser
    {
        return self::$parser ??= (new ParserFactory)->createForNewestSupportedVersion();
    }
}

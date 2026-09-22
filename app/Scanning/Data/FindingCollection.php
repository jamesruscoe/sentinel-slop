<?php

declare(strict_types=1);

namespace App\Scanning\Data;

use App\Scanning\Enums\Severity;
use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * @implements IteratorAggregate<int, Finding>
 */
final class FindingCollection implements Countable, IteratorAggregate
{
    /** @var list<Finding> */
    private array $findings = [];

    /**
     * @param  iterable<Finding>  $findings
     */
    public function __construct(iterable $findings = [])
    {
        foreach ($findings as $finding) {
            $this->add($finding);
        }
    }

    public function add(Finding $finding): self
    {
        $this->findings[] = $finding;

        return $this;
    }

    public function merge(FindingCollection $other): self
    {
        $merged = new self($this->findings);
        foreach ($other as $finding) {
            $merged->add($finding);
        }

        return $merged;
    }

    /**
     * @param  callable(Finding): bool  $predicate
     */
    public function filter(callable $predicate): self
    {
        return new self(array_values(array_filter($this->findings, $predicate)));
    }

    /**
     * @param  callable(Finding): Finding  $mapper
     */
    public function map(callable $mapper): self
    {
        return new self(array_map($mapper, $this->findings));
    }

    public function withSeverity(Severity $severity): self
    {
        return $this->filter(fn (Finding $f) => $f->severity === $severity);
    }

    public function securityCritical(): self
    {
        return $this->filter(fn (Finding $f) => $f->isSecurityCritical());
    }

    /** @return list<Finding> */
    public function all(): array
    {
        return $this->findings;
    }

    public function isEmpty(): bool
    {
        return $this->findings === [];
    }

    public function count(): int
    {
        return count($this->findings);
    }

    /** @return Traversable<int, Finding> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->findings);
    }

    /** @return list<array<string, mixed>> */
    public function toArray(): array
    {
        return array_map(fn (Finding $f) => $f->toArray(), $this->findings);
    }

    /**
     * @param  list<array<string, mixed>>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(array_map(fn (array $row) => Finding::fromArray($row), $data));
    }
}

<?php

declare(strict_types=1);

namespace App\Scanning\Fetch;

use App\Scanning\Exceptions\FetchLimitExceededException;
use Generator;
use RuntimeException;

/**
 * Sequential reader for a gzipped POSIX tar stream, the format of GitHub's
 * tarball endpoint (`git archive`). It never extracts anything on its own:
 * the caller decides per entry whether to stream the data somewhere or
 * discard it, so a symlink, hard link, device or oversized file reaches the
 * disk only if the caller writes it, and memory stays at one chunk.
 *
 * Understands ustar prefixes, GNU long names and links, and pax extended
 * headers (git uses pax for long paths and a global header for the commit).
 */
final class TarStreamReader
{
    private const BLOCK = 512;

    private const CHUNK = 1024 * 1024;

    /** pax and GNU long-name records are small; anything bigger is not a header. */
    private const MAX_META_BYTES = 1024 * 1024;

    /** @var resource */
    private $handle;

    private int $remaining = 0;

    private int $padding = 0;

    /** @var array<string, string> */
    private array $global = [];

    /**
     * @param  resource  $handle  a stream opened with gzopen()
     */
    public function __construct($handle)
    {
        $this->handle = $handle;
    }

    public static function openGzip(string $path): self
    {
        $handle = @gzopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Could not open the archive at {$path}.");
        }

        return new self($handle);
    }

    /**
     * pax global records seen so far. `git archive` stores the commit as `comment`.
     *
     * @return array<string, string>
     */
    public function globalHeaders(): array
    {
        return $this->global;
    }

    /**
     * @return Generator<int, TarEntry>
     */
    public function entries(): Generator
    {
        $longName = null;
        $longLink = null;
        $pax = [];

        while (true) {
            $this->discardCurrent();
            $header = $this->readExact(self::BLOCK);
            if ($header === null || trim($header, "\0") === '') {
                return;
            }

            $name = self::field($header, 0, 100);
            $mode = (int) octdec(trim(self::field($header, 100, 8)) ?: '0');
            $size = (int) octdec(trim(self::field($header, 124, 12)) ?: '0');
            $type = substr($header, 156, 1);
            $link = self::field($header, 157, 100);
            $prefix = substr($header, 257, 5) === 'ustar' ? self::field($header, 345, 155) : '';
            if ($prefix !== '') {
                $name = $prefix.'/'.$name;
            }
            $this->startData($size);

            switch ($type) {
                case 'x':
                    $pax = self::parsePax($this->readMeta());

                    continue 2;
                case 'g':
                    $this->global = array_merge($this->global, self::parsePax($this->readMeta()));

                    continue 2;
                case 'L':
                    $longName = rtrim($this->readMeta(), "\0");

                    continue 2;
                case 'K':
                    $longLink = rtrim($this->readMeta(), "\0");

                    continue 2;
            }

            if ($longName !== null) {
                $name = $longName;
            }
            if ($longLink !== null) {
                $link = $longLink;
            }
            if (isset($pax['path'])) {
                $name = $pax['path'];
            }
            if (isset($pax['linkpath'])) {
                $link = $pax['linkpath'];
            }
            if (isset($pax['size']) && ctype_digit($pax['size'])) {
                $size = (int) $pax['size'];
                $this->startData($size);
            }
            $longName = $longLink = null;
            $pax = [];

            yield new TarEntry($this, $name, $type === "\0" || $type === '' ? '0' : $type, $size, $link, $mode);
        }
    }

    /**
     * Stream the current entry's data to the sink in chunks; returns the bytes delivered.
     *
     * @param  callable(string): void  $sink
     */
    public function copyCurrent(callable $sink): int
    {
        $delivered = 0;
        while ($this->remaining > 0) {
            $chunk = $this->readExact(min(self::CHUNK, $this->remaining));
            if ($chunk === null) {
                throw new RuntimeException('The archive ended in the middle of a file.');
            }
            $this->remaining -= strlen($chunk);
            $delivered += strlen($chunk);
            $sink($chunk);
        }

        return $delivered;
    }

    private function startData(int $size): void
    {
        if ($size < 0) {
            throw new RuntimeException('The archive declares a negative entry size.');
        }
        $this->remaining = $size;
        $this->padding = (self::BLOCK - $size % self::BLOCK) % self::BLOCK;
    }

    private function readMeta(): string
    {
        if ($this->remaining > self::MAX_META_BYTES) {
            throw new FetchLimitExceededException('The archive contains an oversized header record.');
        }
        $data = '';
        $this->copyCurrent(function (string $chunk) use (&$data): void {
            $data .= $chunk;
        });

        return $data;
    }

    private function discardCurrent(): void
    {
        $this->copyCurrent(static fn (string $chunk) => null);
        if ($this->padding > 0) {
            $this->readExact($this->padding);
            $this->padding = 0;
        }
    }

    /**
     * Exactly $length bytes, or null at a clean end of stream.
     */
    private function readExact(int $length): ?string
    {
        $data = '';
        while (strlen($data) < $length) {
            $part = gzread($this->handle, $length - strlen($data));
            if ($part === false || $part === '') {
                if ($data === '') {
                    return null;
                }
                throw new RuntimeException('The archive is truncated.');
            }
            $data .= $part;
        }

        return $data;
    }

    private static function field(string $header, int $offset, int $length): string
    {
        $value = substr($header, $offset, $length);
        $nul = strpos($value, "\0");

        return $nul === false ? $value : substr($value, 0, $nul);
    }

    /**
     * pax records are "<length> <key>=<value>\n".
     *
     * @return array<string, string>
     */
    private static function parsePax(string $data): array
    {
        $records = [];
        $offset = 0;
        $total = strlen($data);
        while ($offset < $total) {
            $space = strpos($data, ' ', $offset);
            if ($space === false) {
                break;
            }
            $length = (int) substr($data, $offset, $space - $offset);
            if ($length <= 0 || $offset + $length > $total) {
                break;
            }
            $record = substr($data, $space + 1, $offset + $length - $space - 2);
            $equals = strpos($record, '=');
            if ($equals !== false) {
                $records[substr($record, 0, $equals)] = substr($record, $equals + 1);
            }
            $offset += $length;
        }

        return $records;
    }
}

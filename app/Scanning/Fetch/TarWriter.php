<?php

declare(strict_types=1);

namespace App\Scanning\Fetch;

use RuntimeException;

/**
 * Writes a gzipped ustar/pax archive, the shape `git archive` produces.
 * Used by the local-directory content source (development) and by the test
 * fake, never in production; it exists so the same TarballFetcher runs
 * everywhere and the reader can be tested against symlinks, hard links and
 * long paths that a fixture directory could not hold.
 */
final class TarWriter
{
    private const BLOCK = 512;

    /** @var resource */
    private $handle;

    /**
     * @param  resource  $handle  a stream opened with gzopen(..., 'wb')
     */
    public function __construct($handle)
    {
        $this->handle = $handle;
    }

    public static function createGzip(string $path): self
    {
        $handle = @gzopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException("Could not create the archive at {$path}.");
        }

        return new self($handle);
    }

    /**
     * @param  array<string, string>  $records
     */
    public function globalHeader(array $records): void
    {
        $this->raw('pax_global_header', 'g', self::pax($records));
    }

    public function file(string $name, string $content, int $mode = 0644): void
    {
        $this->entry($name, '0', $content, mode: $mode);
    }

    public function directory(string $name): void
    {
        $this->entry(rtrim($name, '/').'/', '5', '', mode: 0755);
    }

    public function symlink(string $name, string $target): void
    {
        $this->entry($name, '2', '', $target, 0777);
    }

    public function hardLink(string $name, string $target): void
    {
        $this->entry($name, '1', '', $target);
    }

    public function close(): void
    {
        gzwrite($this->handle, str_repeat("\0", self::BLOCK * 2));
        gzclose($this->handle);
    }

    /**
     * Any name or link target over 100 bytes goes through a pax extended header, as git does.
     */
    private function entry(string $name, string $type, string $data, string $link = '', int $mode = 0644): void
    {
        $pax = [];
        if (strlen($name) > 100) {
            $pax['path'] = $name;
        }
        if (strlen($link) > 100) {
            $pax['linkpath'] = $link;
        }
        if ($pax !== []) {
            $this->raw('././@PaxHeader', 'x', self::pax($pax));
        }

        $this->raw(substr($name, 0, 100), $type, $data, substr($link, 0, 100), $mode);
    }

    private function raw(string $name, string $type, string $data, string $link = '', int $mode = 0644): void
    {
        $size = strlen($data);
        $header = str_pad($name, 100, "\0")
            .sprintf("%07o\0", $mode)
            .sprintf("%07o\0", 0)
            .sprintf("%07o\0", 0)
            .sprintf("%011o\0", $size)
            .sprintf("%011o\0", 0)
            .'        '
            .$type
            .str_pad($link, 100, "\0")
            ."ustar\0"
            .'00'
            .str_pad('', 32, "\0")
            .str_pad('', 32, "\0")
            .sprintf("%07o\0", 0)
            .sprintf("%07o\0", 0)
            .str_pad('', 155, "\0");
        $header = str_pad($header, self::BLOCK, "\0");
        $checksum = array_sum(array_map('ord', str_split($header)));
        $header = substr_replace($header, sprintf("%06o\0 ", $checksum), 148, 8);

        gzwrite($this->handle, $header);
        if ($size > 0) {
            gzwrite($this->handle, $data);
            $padding = (self::BLOCK - $size % self::BLOCK) % self::BLOCK;
            if ($padding > 0) {
                gzwrite($this->handle, str_repeat("\0", $padding));
            }
        }
    }

    /**
     * @param  array<string, string>  $records
     */
    private static function pax(array $records): string
    {
        $out = '';
        foreach ($records as $key => $value) {
            $body = " {$key}={$value}\n";
            $length = strlen($body) + 1;
            while (strlen((string) $length) + strlen($body) !== $length) {
                $length = strlen((string) $length) + strlen($body);
            }
            $out .= $length.$body;
        }

        return $out;
    }
}

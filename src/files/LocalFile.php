<?php

namespace justinholtweb\erpy\files;

use DateTimeImmutable;

/**
 * A file that is on this server now, ready to read — either where it always lives, or a copy
 * fetched from a URL or an SFTP server, which `release()` deletes.
 */
final class LocalFile
{
    public function __construct(
        public readonly string $path,
        /** The file's own name where it came from, for messages and the run log. */
        public readonly string $name,
        /** When the source last changed it, in UTC, if the source says. */
        public readonly ?DateTimeImmutable $modifiedAt = null,
        public readonly bool $temporary = false,
    ) {
    }

    public function size(): int
    {
        return (int)@filesize($this->path);
    }

    public function release(): void
    {
        if ($this->temporary && is_file($this->path)) {
            @unlink($this->path);
        }
    }

    /**
     * A temporary file under Craft's runtime folder, which is never web-readable.
     */
    public static function tempPath(string $prefix = 'erpy-file-'): string
    {
        $dir = \Craft::$app->getPath()->getTempPath() . DIRECTORY_SEPARATOR . 'erpy';

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir . DIRECTORY_SEPARATOR . $prefix . bin2hex(random_bytes(8));
    }
}

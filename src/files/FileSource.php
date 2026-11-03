<?php

namespace justinholtweb\erpy\files;

use DateTimeInterface;

/**
 * Somewhere files come from and go to: a folder on this server, a URL, an SFTP server.
 *
 * Not HTTP in disguise. A folder has no status codes and an SFTP server no headers, so the file
 * connector talks to this rather than to `Transport` — only the URL source uses `Transport`
 * underneath, and gets its logging, retries and redaction from it.
 *
 * Paths handed to a source are always relative to the folder or URL an admin configured. A
 * source refuses `..`, absolute paths and other schemes, because the per-file names can be
 * edited by anyone who manages connections and the base location cannot.
 */
interface FileSource
{
    /**
     * Bring one file within reach. Returns null when the source can tell it has not changed
     * since `$since`. A path containing `*` or `?` means the newest file that matches.
     *
     * @throws FileException
     */
    public function fetch(string $path, ?DateTimeInterface $since = null): ?LocalFile;

    /**
     * @throws FileException
     */
    public function exists(string $path): bool;

    /**
     * Write a whole file atomically: under a temporary name first, then renamed, because an ERP
     * polling the folder will otherwise pick up half an order.
     *
     * @throws FileException
     */
    public function write(string $path, string $contents): void;

    /**
     * Prove the location is reachable, and say what is there.
     *
     * @throws FileException
     */
    public function probe(): string;

    /** Where this is, for a merchant reading an error — never with a password in it. */
    public function describe(): string;
}

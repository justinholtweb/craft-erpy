<?php

namespace justinholtweb\erpy\files;

/**
 * Something went wrong reaching or reading a file, in words a merchant can act on.
 *
 * `$retryable` is false when trying again unchanged cannot help (a path outside the folder, a
 * server Erpy cannot identify); `$auth` marks a refused login, which the failure alerts treat as
 * expired credentials rather than a bad afternoon.
 */
class FileException extends \RuntimeException
{
    public function __construct(
        string $message,
        public bool $retryable = true,
        public bool $auth = false,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function permanent(string $message): self
    {
        return new self($message, retryable: false);
    }

    public static function auth(string $message): self
    {
        return new self($message, retryable: true, auth: true);
    }
}

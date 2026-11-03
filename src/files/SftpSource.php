<?php

namespace justinholtweb\erpy\files;

use Craft;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;
use Throwable;

/**
 * An SFTP server: the ERP's own, or a drop box between the two.
 *
 * Erpy will not log in to a server it cannot identify. The host key fingerprint is a required,
 * admin-only setting, and a server presenting any other key is refused before a password is
 * sent — otherwise anyone who can answer on that hostname collects the credentials and the
 * order files. With no fingerprint saved, the refusal names the one the server presented, so
 * the merchant can check it with whoever runs the server and paste it in.
 */
class SftpSource extends BaseSource
{
    /** SFTP's file type for a plain file. phpseclib only defines its constant once connected. */
    private const REGULAR_FILE = 1;

    private ?SFTP $sftp = null;

    private string $base;

    public function __construct(
        private string $host,
        private int $port,
        private string $username,
        private string $password,
        private string $privateKey,
        private string $fingerprint,
        string $directory = '',
        private int $timeout = 30,
    ) {
        $this->host = trim($this->host);

        if ($this->host === '' || preg_match('#[/\s@]#', $this->host)) {
            throw FileException::permanent(Craft::t('erpy', 'The SFTP host must be a bare host name, such as sftp.example.com.'));
        }

        $directory = trim(str_replace('\\', '/', $directory));
        $this->base = $directory === '' ? '.' : rtrim($directory, '/');
    }

    public function fetch(string $path, ?DateTimeInterface $since = null): ?LocalFile
    {
        $sftp = $this->session();
        $remote = $this->locate($path);

        if ($remote === null) {
            throw new FileException(Craft::t('erpy', 'There is no {name} in {dir} on {host}.', ['name' => self::clean($path), 'dir' => $this->base, 'host' => $this->host]));
        }

        $stat = $sftp->stat($remote);
        $modifiedAt = isset($stat['mtime'])
            ? (new DateTimeImmutable('@' . (int)$stat['mtime']))->setTimezone(new DateTimeZone('UTC'))
            : null;

        if ($since !== null && $modifiedAt !== null && $modifiedAt < $since) {
            return null;
        }

        $local = LocalFile::tempPath();

        if ($sftp->get($remote, $local) === false) {
            @unlink($local);

            throw new FileException(Craft::t('erpy', 'Downloading {name} from {host} failed: {error}', ['name' => basename($remote), 'host' => $this->host, 'error' => (string)$sftp->getLastSFTPError()]));
        }

        return new LocalFile($local, basename($remote), $modifiedAt, temporary: true);
    }

    public function exists(string $path): bool
    {
        return $this->session()->file_exists($this->join(self::clean($path)));
    }

    public function write(string $path, string $contents): void
    {
        $sftp = $this->session();
        $target = $this->join(self::clean($path));
        $temp = $this->join(self::tempName(self::clean($path)));

        if (!$sftp->put($temp, $contents, SFTP::SOURCE_STRING)) {
            throw new FileException(Craft::t('erpy', 'Writing {name} to {host} failed: {error}', ['name' => basename($target), 'host' => $this->host, 'error' => (string)$sftp->getLastSFTPError()]));
        }

        // Renamed only once complete, so an ERP polling the folder never reads half an order.
        // Plain SFTP rename refuses to replace a file; OpenSSH's posix-rename replaces it
        // atomically, and on a server without it the old file goes first.
        $renamed = $sftp->posix_rename($temp, $target);

        if (!$renamed && $sftp->file_exists($target)) {
            $sftp->delete($target, false);
        }

        if (!$renamed && !$sftp->rename($temp, $target)) {
            $error = (string)$sftp->getLastSFTPError();
            $sftp->delete($temp, false);

            throw new FileException(Craft::t('erpy', 'Writing {name} to {host} failed: {error}', ['name' => basename($target), 'host' => $this->host, 'error' => $error]));
        }
    }

    public function probe(): string
    {
        $sftp = $this->session();

        if (!$sftp->is_dir($this->base)) {
            throw new FileException(Craft::t('erpy', 'Logged in to {host}, but there is no folder {dir}.', ['host' => $this->host, 'dir' => $this->base]));
        }

        $files = array_filter(
            (array)$sftp->rawlist($this->base),
            static fn($entry, $name) => is_array($entry) && ($entry['type'] ?? 0) === self::REGULAR_FILE && !str_starts_with((string)$name, '.'),
            ARRAY_FILTER_USE_BOTH,
        );

        return Craft::t('erpy', 'Logged in to {host}; {dir} holds {n, plural, =1{one file} other{# files}}.', ['host' => $this->host, 'dir' => $this->base, 'n' => count($files)]);
    }

    public function describe(): string
    {
        return 'sftp://' . $this->host . ($this->port !== 22 ? ':' . $this->port : '') . '/' . ltrim($this->base, './');
    }

    /**
     * The SHA-256 fingerprint of a host key line (`ssh-ed25519 AAAA…`), in the form `ssh-keygen
     * -l` prints: `SHA256:` and unpadded base64.
     */
    public static function fingerprintOf(string $hostKey): string
    {
        $blob = base64_decode(explode(' ', trim($hostKey))[1] ?? '', true);

        return 'SHA256:' . rtrim(base64_encode(hash('sha256', (string)$blob, true)), '=');
    }

    public static function normaliseFingerprint(string $fingerprint): string
    {
        $fingerprint = trim($fingerprint);

        if ($fingerprint === '') {
            return '';
        }

        return 'SHA256:' . rtrim((string)preg_replace('/^SHA256:/i', '', $fingerprint), '=');
    }

    private function session(): SFTP
    {
        if ($this->sftp !== null) {
            return $this->sftp;
        }

        try {
            $sftp = new SFTP($this->host, $this->port, $this->timeout);
            $hostKey = $sftp->getServerPublicHostKey();
        } catch (Throwable $e) {
            throw new FileException(Craft::t('erpy', 'Could not reach {host}: {message}', ['host' => $this->host, 'message' => $e->getMessage()]), previous: $e);
        }

        if (!is_string($hostKey) || $hostKey === '') {
            throw new FileException(Craft::t('erpy', 'Could not reach {host}, or it did not prove its identity.', ['host' => $this->host]));
        }

        $presented = self::fingerprintOf($hostKey);
        $expected = self::normaliseFingerprint($this->fingerprint);

        if ($expected === '') {
            throw FileException::permanent(Craft::t('erpy', '{host} presented the key {fingerprint}. If that is your server, save it as the server fingerprint; Erpy will not send credentials to a server it cannot identify.', ['host' => $this->host, 'fingerprint' => $presented]));
        }

        if (!hash_equals($expected, $presented)) {
            throw FileException::permanent(Craft::t('erpy', '{host} presented the key {fingerprint}, not the one saved for it. Erpy did not log in. If the server was rebuilt, confirm the new key with whoever runs it before saving it.', ['host' => $this->host, 'fingerprint' => $presented]));
        }

        try {
            if (trim($this->privateKey) !== '') {
                $pem = $this->privateKey;

                // A path is easier to keep out of the database than a multi-line key.
                if (!str_contains($pem, '-----BEGIN') && !str_starts_with($pem, 'PuTTY')) {
                    $file = (string)Craft::getAlias(trim($pem), false) ?: trim($pem);
                    $pem = is_readable($file) ? (string)file_get_contents($file) : '';

                    if ($pem === '') {
                        throw FileException::permanent(Craft::t('erpy', 'The SFTP private key is neither a key nor a readable key file.'));
                    }
                }

                $key = PublicKeyLoader::load($pem, $this->password !== '' ? $this->password : false);
                $ok = $sftp->login($this->username, $key);
            } else {
                $ok = $sftp->login($this->username, $this->password);
            }
        } catch (FileException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw FileException::permanent(Craft::t('erpy', 'The SFTP private key could not be read: {message}', ['message' => $e->getMessage()]));
        }

        if (!$ok) {
            throw FileException::auth(Craft::t('erpy', '{host} refused the login for {user}.', ['host' => $this->host, 'user' => $this->username]));
        }

        return $this->sftp = $sftp;
    }

    private function locate(string $path): ?string
    {
        $path = self::clean($path);
        $sftp = $this->session();

        if (!self::isPattern($path)) {
            $remote = $this->join($path);

            return $sftp->is_file($remote) ? $remote : null;
        }

        $dir = $this->join(dirname($path) === '.' ? '' : dirname($path));
        $candidates = [];

        foreach ((array)$sftp->rawlist($dir) as $name => $entry) {
            if (is_array($entry) && ($entry['type'] ?? 0) === self::REGULAR_FILE) {
                $candidates[(string)$name] = (int)($entry['mtime'] ?? 0);
            }
        }

        $name = self::newest($candidates, basename($path));

        return $name === null ? null : rtrim($dir, '/') . '/' . $name;
    }

    private function join(string $relative): string
    {
        return $relative === '' ? $this->base : rtrim($this->base, '/') . '/' . $relative;
    }
}

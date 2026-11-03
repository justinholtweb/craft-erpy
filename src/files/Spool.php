<?php

namespace justinholtweb\erpy\files;

use Craft;

/**
 * One file's records, parsed once and kept for the length of a run.
 *
 * The engine pages: it asks for 500 records, then the next 500. Re-reading a 100,000-line XML file
 * from the top for every page would parse it two hundred times. So the first page reads the whole
 * file into a spool — UTF-8 JSON, one record per line, in Craft's runtime folder — and each later
 * page seeks straight to its byte offset. The offset is the page cursor.
 *
 * The last page deletes the spool. One abandoned by a killed worker is swept a day later.
 */
final class Spool
{
    private const MAX_AGE = 86400;

    private function __construct(public readonly string $id, private string $path)
    {
    }

    /**
     * Read every record into a new spool.
     *
     * @param iterable<array{line:int,row:array,error?:string}> $records
     */
    public static function fill(iterable $records): self
    {
        self::sweep();

        $id = bin2hex(random_bytes(12));
        $path = self::pathFor($id);
        $handle = @fopen($path, 'wb');

        if ($handle === false) {
            throw new FileException(Craft::t('erpy', 'Erpy could not write to {dir}.', ['dir' => dirname($path)]));
        }

        try {
            foreach ($records as $record) {
                $line = ['l' => $record['line'], 'r' => $record['row']];

                if (isset($record['error'])) {
                    $line['e'] = $record['error'];
                }

                fwrite($handle, json_encode($line, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
            }
        } catch (\Throwable $e) {
            fclose($handle);
            @unlink($path);

            throw $e;
        }

        fclose($handle);

        return new self($id, $path);
    }

    public static function open(string $id): ?self
    {
        if (!preg_match('/^[a-f0-9]{24}$/', $id)) {
            return null;
        }

        $path = self::pathFor($id);

        return is_file($path) ? new self($id, $path) : null;
    }

    /**
     * Records from a byte offset. `$keepTogether`, given the last record taken and the next one,
     * says whether the next belongs with it — so a shipment spread over several rows is not
     * split across two pages.
     *
     * @return array{0:array<int,array{l:int,r:array,e?:string}>,1:?int} the records, and the
     *     offset to continue from (null when the spool is finished)
     */
    public function read(int $offset, int $limit, ?callable $keepTogether = null): array
    {
        $handle = @fopen($this->path, 'rb');

        if ($handle === false) {
            throw new FileException(Craft::t('erpy', 'The spooled copy of the file has gone. Run the sync again.'));
        }

        try {
            fseek($handle, $offset);
            $records = [];
            $last = null;

            while (true) {
                $position = ftell($handle);
                $line = fgets($handle);

                if ($line === false) {
                    return [$records, null];
                }

                $record = json_decode($line, true);

                if (!is_array($record)) {
                    continue;
                }

                if (count($records) >= $limit && !($keepTogether && $last !== null && $keepTogether($last, $record))) {
                    return [$records, (int)$position];
                }

                $records[] = $record;
                $last = $record;
            }
        } finally {
            fclose($handle);
        }
    }

    public function delete(): void
    {
        @unlink($this->path);
    }

    public static function directory(): string
    {
        $dir = Craft::$app->getPath()->getRuntimePath() . DIRECTORY_SEPARATOR . 'erpy' . DIRECTORY_SEPARATOR . 'spool';

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir;
    }

    private static function pathFor(string $id): string
    {
        return self::directory() . DIRECTORY_SEPARATOR . $id . '.jsonl';
    }

    private static function sweep(): void
    {
        foreach (glob(self::directory() . DIRECTORY_SEPARATOR . '*.jsonl') ?: [] as $file) {
            if (@filemtime($file) < time() - self::MAX_AGE) {
                @unlink($file);
            }
        }
    }
}

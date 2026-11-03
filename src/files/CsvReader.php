<?php

namespace justinholtweb\erpy\files;

use Craft;

/**
 * Delimited text: CSV, semicolon-separated (what Excel writes wherever the decimal mark is a
 * comma), tab-separated, pipe-separated.
 *
 * Bytes are read as they are and each cell converted on its own, so one row that is not valid in
 * the file's encoding is rejected by itself instead of ending the file. Delimiters and quotes are
 * ASCII in every encoding offered here, so splitting before converting is safe.
 */
class CsvReader implements RecordReader
{
    public const DELIMITERS = [',', ';', "\t", '|'];

    public function __construct(
        private string $encoding = Encoding::UTF8,
        private string $delimiter = 'auto',
        private string $enclosure = '"',
        private bool $headerRow = true,
    ) {
        $this->encoding = Encoding::normalise($this->encoding);
        $this->enclosure = $this->enclosure === '' ? '"' : $this->enclosure[0];
    }

    public function read(string $path): \Generator
    {
        $widened = null;

        if (Encoding::isWide($this->encoding)) {
            $widened = $this->widen($path);
            $path = $widened;
        }

        $encoding = $widened ? Encoding::UTF8 : $this->encoding;
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw new FileException(Craft::t('erpy', 'The file could not be opened.'));
        }

        try {
            $delimiter = $this->delimiter === 'auto' || $this->delimiter === ''
                ? $this->sniff($handle)
                : ($this->delimiter === '\t' || $this->delimiter === 'tab' ? "\t" : $this->delimiter[0]);

            // A UTF-8 byte-order mark would otherwise end up inside the first column's name.
            if (fread($handle, 3) !== "\xEF\xBB\xBF") {
                rewind($handle);
            }

            $header = null;
            $line = 0;

            while (($cells = fgetcsv($handle, null, $delimiter, $this->enclosure, '')) !== false) {
                $line++;

                if ($cells === [null]) {
                    continue;
                }

                $converted = [];
                $valid = true;

                foreach ($cells as $cell) {
                    $value = Encoding::toUtf8((string)$cell, $encoding);

                    if ($value === null) {
                        $valid = false;
                        $value = mb_scrub((string)$cell, 'UTF-8');
                    }

                    $converted[] = $value;
                }

                if ($this->headerRow && $header === null) {
                    if (!$valid) {
                        throw FileException::permanent(Craft::t('erpy', 'The header row is not valid {encoding}. Check which encoding the ERP saves this file in.', ['encoding' => $encoding]));
                    }

                    $header = $this->headerNames($converted);
                    continue;
                }

                $names = $header ?? array_map('strval', range(1, count($converted)));
                $row = $this->combine($names, $converted, $error);

                if (!$valid) {
                    yield ['line' => $line, 'row' => $row, 'error' => Craft::t('erpy', 'Row {line} is not valid {encoding}.', ['line' => $line, 'encoding' => $encoding])];
                } elseif ($error !== null) {
                    yield ['line' => $line, 'row' => $row, 'error' => Craft::t('erpy', 'Row {line}: {error}', ['line' => $line, 'error' => $error])];
                } else {
                    yield ['line' => $line, 'row' => $row];
                }
            }
        } finally {
            fclose($handle);

            if ($widened !== null) {
                @unlink($widened);
            }
        }
    }

    /**
     * Pick the delimiter that splits the first line into the most columns, counting only those
     * outside quotes.
     *
     * @param resource $handle
     */
    private function sniff($handle): string
    {
        $first = (string)fgets($handle, 65536);
        rewind($handle);

        $best = ',';
        $bestCount = 0;

        foreach (self::DELIMITERS as $candidate) {
            $count = 0;
            $quoted = false;

            for ($i = 0, $length = strlen($first); $i < $length; $i++) {
                if ($first[$i] === $this->enclosure) {
                    $quoted = !$quoted;
                } elseif (!$quoted && $first[$i] === $candidate) {
                    $count++;
                }
            }

            if ($count > $bestCount) {
                $best = $candidate;
                $bestCount = $count;
            }
        }

        return $best;
    }

    /**
     * @param string[] $cells
     * @return string[]
     */
    private function headerNames(array $cells): array
    {
        $names = [];

        foreach ($cells as $index => $cell) {
            $name = trim($cell);
            $name = $name === '' ? 'column' . ($index + 1) : $name;
            $unique = $name;
            $n = 2;

            while (in_array($unique, $names, true)) {
                $unique = $name . '_' . $n++;
            }

            $names[] = $unique;
        }

        return $names;
    }

    /**
     * Pair a row with the header. Extra cells that are empty are a trailing delimiter, not data;
     * any other mismatch means the columns no longer line up and nothing in the row can be
     * trusted to be what its header says.
     *
     * @param string[] $names
     * @param string[] $cells
     */
    private function combine(array $names, array $cells, ?string &$error): array
    {
        $error = null;

        while (count($cells) > count($names) && end($cells) === '') {
            array_pop($cells);
        }

        if (count($cells) !== count($names)) {
            $error = Craft::t('erpy', 'it has {cells, plural, =1{one column} other{# columns}}; the header has {header}.', ['cells' => count($cells), 'header' => count($names)]);
            $row = [];

            foreach ($cells as $index => $cell) {
                $row[$names[$index] ?? (string)($index + 1)] = $cell;
            }

            return $row;
        }

        return array_combine($names, $cells);
    }

    private function widen(string $path): string
    {
        $contents = (string)file_get_contents($path);
        $converted = @iconv($this->encoding, 'UTF-8', $contents);

        if ($converted === false) {
            throw FileException::permanent(Craft::t('erpy', 'The file is not valid {encoding}.', ['encoding' => $this->encoding]));
        }

        $copy = LocalFile::tempPath('erpy-utf8-');
        file_put_contents($copy, Encoding::stripBom($converted));

        return $copy;
    }
}

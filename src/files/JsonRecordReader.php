<?php

namespace justinholtweb\erpy\files;

use Craft;

/**
 * JSON: an array of records, at the top level or under a dotted path (`data.items`).
 *
 * JSON cannot be streamed without a parser this plugin does not ship, so the file is decoded
 * whole. That is fine for the exports this is for; a stock list large enough to matter is
 * almost always CSV.
 */
class JsonRecordReader implements RecordReader
{
    public function __construct(private string $recordPath = '', private string $encoding = Encoding::UTF8)
    {
        $this->encoding = Encoding::normalise($this->encoding);
    }

    public function read(string $path): \Generator
    {
        $contents = (string)@file_get_contents($path);
        $contents = Encoding::toUtf8($this->encoding === Encoding::UTF8 ? Encoding::stripBom($contents) : $contents, $this->encoding);

        if ($contents === null) {
            throw FileException::permanent(Craft::t('erpy', 'The file is not valid {encoding}.', ['encoding' => $this->encoding]));
        }

        try {
            $data = json_decode(Encoding::stripBom($contents), true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException $e) {
            throw FileException::permanent(Craft::t('erpy', 'The file is not valid JSON: {message}. Nothing from this file was applied.', ['message' => $e->getMessage()]));
        }

        foreach (array_filter(explode('.', trim($this->recordPath)), static fn($part) => $part !== '') as $segment) {
            if (!is_array($data) || !array_key_exists($segment, $data)) {
                throw FileException::permanent(Craft::t('erpy', 'The file has nothing at the record path “{path}”.', ['path' => $this->recordPath]));
            }

            $data = $data[$segment];
        }

        if (!is_array($data)) {
            throw FileException::permanent(Craft::t('erpy', 'The record path “{path}” does not lead to a list of records.', ['path' => $this->recordPath]));
        }

        // A single object where a list was expected is one record, not one record per field.
        if (!array_is_list($data)) {
            $data = [$data];
        }

        foreach ($data as $index => $record) {
            if (is_array($record) && !array_is_list($record)) {
                yield ['line' => $index + 1, 'row' => $record];
            } else {
                yield ['line' => $index + 1, 'row' => ['value' => $record], 'error' => Craft::t('erpy', 'Record {n} is not an object.', ['n' => $index + 1])];
            }
        }
    }
}

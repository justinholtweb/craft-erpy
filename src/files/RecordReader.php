<?php

namespace justinholtweb\erpy\files;

/**
 * Turns one file into a stream of records.
 *
 * Each yielded value is either `['line' => n, 'row' => [...]]` or, for a record that could not be
 * read, `['line' => n, 'error' => '…', 'row' => [...what could be salvaged]]`. A reader never
 * holds the whole file in memory unless the format leaves it no choice (JSON).
 */
interface RecordReader
{
    /**
     * @return \Generator<int,array{line:int,row:array,error?:string}>
     * @throws FileException when the file as a whole cannot be read
     */
    public function read(string $path): \Generator;
}

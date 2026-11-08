<?php

namespace justinholtweb\erpy\files;

use Craft;
use DOMElement;
use XMLReader;

/**
 * XML, streamed, one record element at a time.
 *
 * The record path is a deliberately small subset of XPath: `/Export/Items/Item` (that exact
 * chain from the root), or `//Item` / `Item` (that element wherever it appears). Namespace
 * prefixes are ignored, because an ERP's export rarely uses them consistently.
 *
 * A record becomes an array: child elements by name (a repeated one becomes a list), attributes
 * as `@name`, and the text of an element that also has attributes as `#`. So
 * `<Item code="A1"><Price currency="EUR">9,90</Price></Item>` maps as `raw.@code`,
 * `raw.Price.#` and `raw.Price.@currency`.
 *
 * A file that stops being well-formed half way is refused whole: a truncated XML file is usually
 * one the ERP was still writing, and applying its first half would look like success.
 */
class XmlRecordReader implements RecordReader
{
    /** @var string[] */
    private array $chain;

    private bool $anywhere;

    public function __construct(string $recordPath, private string $encoding = Encoding::UTF8)
    {
        $recordPath = trim($recordPath);

        if ($recordPath === '') {
            throw FileException::permanent(Craft::t('erpy', 'Set the record path for this XML file — the element that holds one record, such as /Export/Items/Item.'));
        }

        $this->anywhere = !str_starts_with($recordPath, '/') || str_starts_with($recordPath, '//');
        $this->chain = array_values(array_filter(
            array_map(static fn(string $part) => self::local($part), explode('/', $recordPath)),
            static fn(string $part) => $part !== '',
        ));

        if ($this->chain === []) {
            throw FileException::permanent(Craft::t('erpy', 'The record path “{path}” names no element.', ['path' => $recordPath]));
        }

        $this->encoding = Encoding::normalise($this->encoding);
    }

    public function read(string $path): \Generator
    {
        $head = (string)@file_get_contents($path, false, null, 0, 65536);

        // An export has no use for entity declarations, and refusing them outright closes off
        // billion-laughs expansion whatever the libxml version does about it.
        if (stripos($head, '<!ENTITY') !== false) {
            throw FileException::permanent(Craft::t('erpy', 'The XML file declares entities, which Erpy does not read.'));
        }

        // The file's own declaration wins; the setting is for a file that does not say.
        $declared = preg_match('/^(\xEF\xBB\xBF)?\s*<\?xml[^>]*\sencoding\s*=/i', $head) === 1;
        $encoding = $declared || $this->encoding === Encoding::UTF8 ? null : $this->encoding;

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $reader = new XMLReader();

        // LIBXML_NONET keeps a DOCTYPE from fetching anything; entities are never substituted.
        $opened = $reader->open($path, $encoding, LIBXML_NONET);

        if (!$opened) {
            libxml_use_internal_errors($previous);

            throw FileException::permanent(Craft::t('erpy', 'The XML file could not be opened.'));
        }

        // The element names from the root down to where the reader is, kept by depth so empty
        // elements and end tags need no bookkeeping of their own.
        $stack = [];
        $count = 0;

        try {
            $moved = $reader->read();

            while ($moved) {
                // The byte scan above misses a UTF-16 file or a declaration past 64 KB; the parser
                // does not. A DOCTYPE arrives before the root, so before any entity is referenced.
                if ($reader->nodeType === XMLReader::DOC_TYPE || $reader->nodeType === XMLReader::ENTITY_REF) {
                    throw FileException::permanent(Craft::t('erpy', 'The XML file declares a DOCTYPE or entities, which Erpy does not read.'));
                }

                if ($reader->nodeType !== XMLReader::ELEMENT) {
                    $moved = $reader->read();
                    continue;
                }

                $stack = array_slice($stack, 0, $reader->depth);
                $stack[] = $reader->localName;

                if (!$this->matches($stack)) {
                    $moved = $reader->read();
                    continue;
                }

                // A record cut off mid-way fails here: libxml reports it as a warning, which
                // Craft would turn into an exception without the line number a merchant needs.
                $node = @$reader->expand();
                $this->throwIfBroken();

                if ($node === false) {
                    throw FileException::permanent(Craft::t('erpy', 'The XML stops being readable after record {n}. Nothing from this file was applied.', ['n' => $count]));
                }

                if ($node instanceof DOMElement) {
                    $count++;
                    $row = self::toArray($node);
                    yield ['line' => $node->getLineNo() ?: $count, 'row' => is_array($row) ? $row : ['#' => $row]];
                }

                // Past the record's whole subtree, to whatever follows it.
                $moved = $reader->next();
            }

            $this->throwIfBroken();
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * @param string[] $stack
     */
    private function matches(array $stack): bool
    {
        if ($this->anywhere) {
            return array_slice($stack, -count($this->chain)) === $this->chain;
        }

        return $stack === $this->chain;
    }

    private function throwIfBroken(): void
    {
        foreach (libxml_get_errors() as $error) {
            if ($error->level >= LIBXML_ERR_ERROR) {
                libxml_clear_errors();

                throw FileException::permanent(Craft::t('erpy', 'The XML stops being readable at line {line}: {message}. Nothing from this file was applied.', [
                    'line' => $error->line,
                    'message' => trim($error->message),
                ]));
            }
        }
    }

    /**
     * @return array|string
     */
    public static function toArray(DOMElement $element): array|string
    {
        $out = [];

        foreach ($element->attributes ?? [] as $attribute) {
            $out['@' . self::local($attribute->nodeName)] = $attribute->nodeValue;
        }

        $hasChildren = false;

        foreach ($element->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }

            $hasChildren = true;
            $name = self::local($child->nodeName);
            $value = self::toArray($child);

            if (!array_key_exists($name, $out)) {
                $out[$name] = $value;
            } elseif (is_array($out[$name]) && array_is_list($out[$name]) && $out[$name] !== []) {
                $out[$name][] = $value;
            } else {
                $out[$name] = [$out[$name], $value];
            }
        }

        if ($hasChildren) {
            return $out;
        }

        $text = trim((string)$element->textContent);

        if ($out === []) {
            return $text;
        }

        $out['#'] = $text;

        return $out;
    }

    private static function local(string $name): string
    {
        $name = trim($name);

        return str_contains($name, ':') ? substr($name, strrpos($name, ':') + 1) : $name;
    }
}

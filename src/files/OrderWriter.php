<?php

namespace justinholtweb\erpy\files;

use Craft;
use craft\web\View;
use DateTimeInterface;
use DOMDocument;
use DOMElement;
use justinholtweb\erpy\models\canonical\ErpOrder;

/**
 * Canonical orders, written out as a file an ERP can import.
 *
 * The shapes are the canonical document as it stands — the same names the payload preview
 * shows — so an ERP's import can be configured against the preview before the first order is
 * placed. When an ERP wants something else, a Twig template takes over and receives the same
 * `orders`.
 */
class OrderWriter
{
    public const XML = 'xml';
    public const CSV = 'csv';
    public const JSON = 'json';

    /** Element names for list items, so `<lines>` holds `<line>`s rather than `<item>`s. */
    private const SINGULAR = [
        'lines' => 'line',
        'discounts' => 'discount',
        'notes' => 'note',
        'addresses' => 'address',
        'contacts' => 'contact',
        'customFields' => 'field',
    ];

    public function __construct(
        private string $format = self::XML,
        private string $encoding = Encoding::UTF8,
        private string $delimiter = ',',
        private string $decimalSeparator = '.',
        private string $template = '',
    ) {
        $this->encoding = Encoding::normalise($this->encoding);

        if (!in_array($this->format, [self::XML, self::CSV, self::JSON], true)) {
            $this->format = self::XML;
        }
    }

    /**
     * @param ErpOrder[] $orders
     */
    public function render(array $orders): string
    {
        if (trim($this->template) !== '') {
            return Encoding::fromUtf8($this->renderTemplate($orders), $this->encoding);
        }

        return match ($this->format) {
            self::CSV => Encoding::fromUtf8($this->csv($orders), $this->encoding),
            // JSON is UTF-8 by definition; an encoding setting cannot apply to it.
            self::JSON => json_encode(['orders' => array_map(fn(ErpOrder $order) => $this->clean($order->toArray()), $orders)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR),
            default => $this->xml($orders),
        };
    }

    public function contentType(): string
    {
        $charset = '; charset=' . strtolower($this->format === self::JSON && $this->template === '' ? Encoding::UTF8 : $this->encoding);

        return match ($this->format) {
            self::CSV => 'text/csv' . $charset,
            self::JSON => 'application/json' . $charset,
            default => 'application/xml' . $charset,
        };
    }

    public function extension(): string
    {
        return $this->format;
    }

    /**
     * @param ErpOrder[] $orders
     */
    private function xml(array $orders): string
    {
        // DOM writes the declared encoding itself, and turns any character the encoding cannot
        // hold into a character reference instead of dropping it.
        $dom = new DOMDocument('1.0', $this->encoding === Encoding::UTF8 ? 'UTF-8' : strtolower($this->encoding));
        $dom->formatOutput = true;

        $root = $dom->createElement('Orders');
        $root->setAttribute('count', (string)count($orders));
        $dom->appendChild($root);

        foreach ($orders as $order) {
            $element = $dom->createElement('Order');
            $this->appendXml($dom, $element, $this->clean($order->toArray()));
            $root->appendChild($element);
        }

        return (string)$dom->saveXML();
    }

    private function appendXml(DOMDocument $dom, DOMElement $parent, array $data, ?string $listName = null): void
    {
        foreach ($data as $key => $value) {
            $name = is_int($key) ? ($listName ?? 'item') : self::elementName((string)$key);
            $child = $dom->createElement($name);

            if (is_array($value)) {
                $this->appendXml($dom, $child, $value, self::SINGULAR[(string)$key] ?? 'item');
            } elseif ($value !== null) {
                $child->appendChild($dom->createTextNode($this->scalar($value, '.')));
            }

            $parent->appendChild($child);
        }
    }

    /**
     * One row per order line, with the order's own fields repeated on each — the shape every
     * ERP's CSV import wizard expects.
     *
     * @param ErpOrder[] $orders
     */
    private function csv(array $orders): string
    {
        $rows = [];
        $columns = [];

        foreach ($orders as $order) {
            $data = $this->clean($order->toArray());
            $header = [];

            foreach ($data as $key => $value) {
                if (!is_array($value)) {
                    $header[$key] = $value;
                } elseif (in_array($key, ['billingAddress', 'shippingAddress'], true)) {
                    foreach ($value as $field => $fieldValue) {
                        if (!is_array($fieldValue)) {
                            $header[$key . '.' . $field] = $fieldValue;
                        }
                    }
                }
            }

            $lines = $data['lines'] ?? [];

            foreach ($lines === [] ? [[]] : $lines as $line) {
                $row = $header;

                foreach ((array)$line as $field => $value) {
                    $row['line.' . $field] = is_array($value) ? implode(' / ', array_map('strval', array_filter($value, 'is_scalar'))) : $value;
                }

                $rows[] = $row;
                $columns += array_fill_keys(array_keys($row), true);
            }
        }

        $columns = array_keys($columns);
        $handle = fopen('php://temp', 'w+b');
        $delimiter = $this->delimiter === "\t" || $this->delimiter === 'tab' ? "\t" : (($this->delimiter === '' || $this->delimiter === 'auto') ? ($this->decimalSeparator === ',' ? ';' : ',') : $this->delimiter[0]);

        fputcsv($handle, $columns, $delimiter, '"', '');

        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn(string $column) => array_key_exists($column, $row) ? $this->scalar($row[$column], $this->decimalSeparator) : '', $columns), $delimiter, '"', '');
        }

        rewind($handle);
        $csv = (string)stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * @param ErpOrder[] $orders
     */
    private function renderTemplate(array $orders): string
    {
        $view = Craft::$app->getView();
        $mode = $view->getTemplateMode();

        try {
            $view->setTemplateMode(View::TEMPLATE_MODE_SITE);

            if (!$view->doesTemplateExist($this->template)) {
                throw FileException::permanent(Craft::t('erpy', 'There is no site template “{template}” to write orders with.', ['template' => $this->template]));
            }

            return $view->renderTemplate($this->template, [
                'orders' => $orders,
                'order' => $orders[0] ?? null,
            ]);
        } finally {
            $view->setTemplateMode($mode);
        }
    }

    /**
     * The canonical document minus what only matters inside Erpy.
     */
    private function clean(array $data): array
    {
        unset($data['extra']);

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->clean($value);
            }
        }

        return $data;
    }

    private function scalar(mixed $value, string $decimalSeparator): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_float($value)) {
            // Money keeps two places; anything finer (a unit price to four) keeps what it has.
            $text = rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
            $places = str_contains($text, '.') ? strlen(substr($text, strpos($text, '.') + 1)) : 0;
            $text = number_format($value, max(2, $places), '.', '');

            return $decimalSeparator === ',' ? str_replace('.', ',', $text) : $text;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        return (string)$value;
    }

    private static function elementName(string $key): string
    {
        $name = (string)preg_replace('/[^A-Za-z0-9_.-]/', '_', $key);

        return preg_match('/^[A-Za-z_]/', $name) ? $name : '_' . $name;
    }
}

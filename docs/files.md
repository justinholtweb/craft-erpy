---
title: File / URL exchange
slug: files
order: 35
summary: For an ERP that exchanges files instead of offering an API — CSV, XML or JSON in, from a folder, a URL, SFTP or an upload; orders out as files, or served at a URL the ERP fetches. Built into Erpy.
---

# File / URL exchange

Plenty of ERPs have no API worth the name. They export stock and prices as a CSV or XML file and
import orders the same way, either from a folder or by fetching a URL the shop provides. Most of
the ERPs small and mid-size shops run work like this, including nearly all the local ones in
south-east Europe: Pantheon, Minimax, Synesis, Luceed, 4D.

The **File / URL exchange** connector is built into Erpy, like the Mock ERP. There's no add-on to
install. Choose it under **Erpy → Connections → New connection**.

A file gets the same handling as NetSuite or Business Central:

- the identity map
- delta watermarks
- dead letters
- the run log
- the mapping screen

A row that can't be read becomes one entry on the Problems screen and the rest of the file still
gets applied. An order collected by the ERP is recorded as delivered, so it isn't served twice.

## What it syncs

| Entity | Direction | Delta sync |
|---|---|---|
| Customers | ERP → Commerce | Yes. An unchanged file is skipped, or rows are filtered by a date column. |
| Products | ERP → Commerce | Yes |
| Prices | ERP → Commerce | Yes |
| Inventory | ERP → Commerce | Yes |
| Orders | Commerce → ERP | Written as files, or served at a URL |
| Order status | ERP → Commerce | Yes |
| Shipments | ERP → Commerce | Yes |

## Where the files are

**Read files from** sets where the files are read from. Only an admin can set or change it, or the
location fields that go with it. That's deliberate, because whoever can set the folder can read
any file the web server can read.

| Source | What to set |
|---|---|
| **A folder on this server** | **Folder**: an absolute path or an alias, e.g. `@storage/erp`. This suits an ERP share mounted on the server, or a folder something else copies files into. |
| **A URL** | **Base URL**, plus a login if the server needs one: username and password, or a bearer token. Each file name is added to the base URL. Leave the file name blank if the base URL *is* the export. |
| **An SFTP server** | **Host**, **port**, **username**, a **password** or a **private key**, the **server fingerprint**, and the **folder**. |
| **Uploaded in the control panel** | Nothing. Each entity gets an **Upload** button on the connection screen, and the latest upload replaces the previous one. |

Each entity's file is named on the **What to sync** table, under that entity's row:

- **File**: the file's name inside the folder or URL, e.g. `stock.csv`. A wildcard such as
  `stock_*.csv` takes the newest match, for an ERP that writes a new file each night. A file name
  can't climb out of the folder (`../`), can't be an absolute path and can't be another address.
- **Record path**: for XML and JSON only. See below.
- **Changed-date column**: optional. When it's set, a delta sync reads only the rows changed since
  the last run.

### SFTP and the server fingerprint

Erpy won't log in to an SFTP server it can't identify. Without that check, anyone who could answer
on that hostname would collect the password and every order file. Leave **SFTP server
fingerprint** blank and press **Test connection**. The test fails and names the fingerprint the
server presented, for example `SHA256:mQ9a…`. Confirm it with whoever runs the server, then paste
it in. To get it yourself, run `ssh-keygen -lf` on the server's host key, or
`ssh-keyscan host | ssh-keygen -lf -`.

If the fingerprint ever changes, Erpy stops and says so. That happens when a server is rebuilt, and
it also happens when someone sits between you and it. Check before saving the new value.

**SFTP private key** takes an environment variable holding the key, or the path to a key file.
Both are easier to keep out of the database than pasting the key in. When a key is set, **SFTP
password** is used as the key's passphrase.

## Reading files

| Setting | |
|---|---|
| **Format** | **Tell from the file** goes by the extension (`.csv`/`.txt`, `.xml`, `.json`), then by the first character. |
| **Encoding** | UTF-8, Windows-1250, ISO-8859-2, Windows-1251, Windows-1252, ISO-8859-1, ISO-8859-15, or UTF-16 (what Excel saves as "Unicode text"). An XML file's own `encoding="…"` declaration takes precedence. |
| **CSV delimiter** | Detected from the first line unless you set it. Excel writes `;` in any locale where `,` is the decimal mark. |
| **CSV files have a header row** | Without one, columns are numbered: `raw.1`, `raw.2`… |
| **Decimal mark** | Point or comma. `12,50` is twelve and a half only once this is set to comma. |
| **Date format** | Optional, a PHP format such as `d.m.Y H:i`. `03.11.2026` and ISO dates are recognised without it. Dates are read in the site's timezone. |

### CSV

One row per record. A row with the wrong number of columns is rejected on its own and appears on
the **Problems** screen.

A stock row with no quantity Erpy can read, or a price row with no price, is rejected the same way
rather than written as zero. That covers a missing column as well as a value like `n/a`. If
the quantity column has a name Erpy doesn't recognise, map `onHand` to it. So does a row whose bytes aren't valid in the file's encoding. The rest of
the file is still applied. A run full of "Row 12 is not valid UTF-8" usually means the ERP saves in
Windows-1250, so change **Encoding**.

### XML

**Record path** names the element that holds one record. It's a small subset of XPath:

- `/Export/Items/Item` matches exactly that chain from the root.
- `//Item`, or just `Item`, matches that element at any depth.

Namespace prefixes are ignored. A record arrives on the mapping screen like this:

```xml
<Item code="A1">
  <Name>Čaj</Name>
  <Price currency="EUR">9,90</Price>
</Item>
```

| Path | Value |
|---|---|
| `raw.@code` | `A1` |
| `raw.Name` | `Čaj` |
| `raw.Price.#` | `9,90` |
| `raw.Price.@currency` | `EUR` |

A repeated element becomes a list: `raw.Tag.0`, `raw.Tag.first`.

A file that declares entities (`<!ENTITY …>`) is refused. Exports never need them, and they're
how an XML file is made to exhaust a server's memory.

**An XML file that stops being well-formed is refused whole.** Nothing from it is applied, the run
fails, and the watermark stays put. A truncated XML file is almost always one the ERP was still
writing, and applying the first half would look like success.

### JSON

The file is an array of objects. **Record path** is a dotted path to that array, e.g. `data.items`,
or blank when the file is the array itself.

### Which column is which

A file has no schema, so the connector guesses from column names. It looks for the English names
plus the Slovene, Croatian, Serbian and German ones these exports actually use:

| Canonical field | Recognised column names (examples) |
|---|---|
| `sku` | `sku`, `code`, `item`, `Šifra`, `Šifra artikla`, `Ident`, `Artikelnummer` |
| `onHand` (stock) | `qty`, `quantity`, `stock`, `Zaloga`, `Količina`, `Stanje`, `Zalihe`, `Bestand` |
| `name` | `name`, `title`, `Naziv`, `Bezeichnung` |
| `unitPrice` / `price` | `price`, `Cena`, `Cijena`, `MPC`, `VPC`, `Preis` |
| `priceListCode` | `pricelist`, `Cenik`, `Cjenik` |
| `customerCode` / `code` | `customer`, `partner`, `Kupac`, `Šifra partnerja` |
| `taxId` | `vat`, `DDV`, `OIB`, `PIB` |
| `orderNumber` | `order`, `Naročilo`, `Narudžba` |
| `shipmentNumber` | `shipment`, `Dobavnica`, `Otpremnica`, `Lieferschein` |
| `trackingNumber` | `tracking`, `Številka paketa` |

Accents, case, spaces and punctuation don't matter, so `ŠIFRA_ARTIKLA` and `Šifra artikla` count
as the same name.

When a guess is wrong, correct it on the mapping screen. A rule whose target is a canonical field
overrides the guess before anything reaches Commerce, for example `sku` ← `raw.Ident`, or `onHand`
← `raw.Prosto` with the **Decimal number with a comma** transform. See [Field mapping](../mapping).

A shipment written as one row per item, with consecutive rows sharing a delivery note number,
becomes one shipment with several lines.

### Delta, and files that are still being written

A delta sync skips a file whose modification time is older than the last run, with a quarter of an
hour's allowance for the other machine's clock, without opening it.
For a URL it sends `If-Modified-Since` and skips on `304 Not Modified`. When **Changed-date
column** is set, only rows changed since the last run are read from a file that has changed.
**Full resync** ignores all of this and reads everything.

A file modified in the last 15 seconds is left for the next run, with a note in the log, because
an ERP may still be writing it. This doesn't apply to uploads.

## Sending orders

**Orders reach the ERP by** is either Erpy writing a file for each order, or the ERP fetching
orders from the export URL. Both go through the same delivery path as every other connector: the
identity map, the run log and the Problems screen. The payload preview shows the document either
way.

### Erpy writes a file for each order

| Setting | |
|---|---|
| **Write order files to** | A folder on this server, or the SFTP server above. |
| **Order folder** | An absolute path or alias, or a folder on the SFTP server. |
| **Order file name** | Default `order-{orderNumber}.{ext}`. It must contain `{orderNumber}`, so every order gets a file of its own, and must end in `.xml`, `.csv`, `.json` or `.txt`. Also available: `{reference}`, `{date}`. |
| **Order format** | XML, CSV (one row per order line, with the order's fields repeated on each), or JSON. |
| **Order encoding** | Any of the encodings above. XML declares the encoding itself and turns any character the encoding can't hold into a character reference. CSV falls back to the nearest equivalent. JSON is always UTF-8. |
| **Order template** | Optional. A site template that writes the file itself. See below. |

Each file is written under a temporary name and renamed once it's complete, so an ERP polling the
folder never picks up half an order. A folder that's missing or can't be written to is retried by
the queue. Once the folder is fixed, the order goes.

The XML follows the canonical order document, the same field names the payload preview shows:

```xml
<?xml version="1.0" encoding="windows-1250"?>
<Orders count="1">
  <Order>
    <orderNumber>a6b5…</orderNumber>
    <reference>KT4KJ2P</reference>
    <customerCode>K0042</customerCode>
    <currency>EUR</currency>
    <billingAddress>…</billingAddress>
    <lines>
      <line><lineNumber>1</lineNumber><sku>Š-1</sku><quantity>3.00</quantity>…</line>
    </lines>
    <total>59.70</total>
    …
  </Order>
</Orders>
```

### The ERP fetches them from the export URL

Set **Export token** to a long random value and give the ERP the **Export URL** shown on the
connection:

```
https://your-site.example/erpy/export/<handle>/orders
```

The ERP sends the token one of three ways:

- an `X-Erpy-Token` header (preferred)
- the password of a basic-auth login, with any username
- `?key=…` in the URL, but only once **Also accept the token in the URL** is on

Use the URL option only for an ERP that can fetch nothing but a plain address, because a token in a
URL ends up in server logs.

**An ERP that can't send a token at all** can be let in by its address instead. Put it in
**Allowed IP addresses** (`203.0.113.7`, or `203.0.113.0/24` for a range, separated by commas). A
request from a listed address doesn't need the token, and a request from anywhere else still does.
You can leave the token empty if the address is the only way in.

Behind a load balancer, proxy or CDN, set Craft's
[`trustedHosts`](https://craftcms.com/docs/5.x/reference/config/general.html#trustedhosts) to that
proxy so Erpy sees the ERP's address and not the proxy's. Until you do, Erpy ignores
`X-Forwarded-For` for this check. Craft's default trusts it from every host, which would let anyone
claim to be the ERP with one header.

| Request | Returns |
|---|---|
| `…/orders` | Orders this connection hasn't delivered yet, oldest first. |
| `…/orders?from=2026-11-01&to=2026-11-03` | Every order in that range, delivered or not, so an ERP that lost a response can ask again. Dates are inclusive and in the site's timezone. |
| `…/orders?all=1` | Every order since the connection was created. |
| `&limit=500` | At most this many (default 200, maximum 1000). |

`from` and `to` take `2026-11-03`, `2026-11-03T14:00:00` and `2026-11-03-14-00`. When the ERP writes
dates its own way, set **Date format in the URL** to its
[PHP date format](https://www.php.net/manual/en/datetime.format.php), for example `d.m.Y` for
`03.11.2026`. A date without a time means the start of that day as `from` and the end of it as
`to`.

An ERP already fetching from a URL of its own, like `/export-orders/orders-time/2026-11-03-00-00`,
can keep it. Add a route in `config/routes.php` that hands the path to the export:

```php
return [
    'export-orders/orders-time/<from>' => [
        'route' => 'erpy/export/orders',
        'defaults' => ['connectionHandle' => 'shop'],
    ],
];
```

The response uses the order format and encoding set above, and an `X-Erpy-Count` header says how
many orders it holds.

Things to know:

- **Fetching is delivering.** An order is recorded as delivered the moment it's served, and the
  next fetch leaves it out. If the ERP's import fails after the download, fetch the date range again.
- **Only `GET`.** A `HEAD` request or a link-preview bot can't mark orders as delivered.
- **A missing or wrong token gets `404`** (unless the request comes from an allowed address), the same answer as a handle that doesn't exist, so the
  URL gives nothing away. The reason is written to Craft's log.
- **A fetch that fails marks nothing delivered.** Recording and writing the response happen in one
  database transaction. If an order template throws, the ERP gets a `500` and the next fetch serves
  the same orders.
- **An order that can't be served right now doesn't hold up the queue.** The export moves past it
  to newer orders and tries it again on the next fetch.
- **Orders placed before the connection existed are left out**, as they are for a connection
  that pushes, so switching an export on doesn't hand the ERP years of history on its first
  fetch. Ask for them with `from` if the ERP does want them.
- In this mode nothing is pushed at checkout, and the Problems screen's **Retry** and
  `erpy/orders/push` refuse to send an order. The ERP has to come and get it.

### Writing orders with a template

When the ERP wants its own layout, put a site template's path in **Order template**. The template
receives `orders`, a list of canonical order documents (`order` is the first one), and its output
is the file, converted to the order encoding:

```twig
{% for o in orders %}
NAR|{{ o.orderNumber }}|{{ o.orderedAt|date('d.m.Y') }}|{{ o.customerCode }}
{% for line in o.lines %}
POS|{{ line.sku }}|{{ line.quantity }}|{{ line.unitPrice|number_format(2, ',', '') }}
{% endfor %}
{% endfor %}
```

The format setting still decides the file extension and the export's content type.

## Order status and shipments coming back

An ERP that confirms orders or posts deliveries as a file closes the loop the same way:

- **Order status**: a file of order numbers and statuses. Columns such as `shipped`, `cancelled`
  and `invoiced` set the flags the [orders screen](../orders) acts on.
- **Shipments**: order number, delivery note number, tracking number and carrier. Add `sku` and
  `quantity` columns for partial deliveries.

## Testing the connection

**Test connection** reads the folder, URL or SFTP server and says how many files it holds. When
the connection writes orders, it also checks the order folder. When it serves orders, it shows
the export URL.

## Things to know

- **Files carry no ids.** A row's natural key (the SKU, the SKU and warehouse, the customer code)
  is also its remote id, so the identity map and change detection work as they do everywhere else.
- **XLSX isn't read.** Save as CSV. Excel's "CSV UTF-8" or "Unicode text" both work.
- **FTP isn't supported**, only SFTP. A plain FTP server sends the password in clear text.
- **A file is spooled once per run.** The first page parses the whole file into Craft's runtime
  folder, and each later page continues from there, so a 100,000-line file is parsed once rather
  than once per page. The spool is deleted when the run ends.

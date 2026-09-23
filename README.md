# labelixa (PHP)

Thin PHP client for the Labelixa API: render, validate and convert
thermal printer label code — ZPL, EPL, TSPL and CPCL — without a printer.

```bash
composer require labelixa/labelixa
```

```php
use Labelixa\Client;

$c = new Client();                                   // anonymous
$c = new Client(apiKey: getenv('LABELIXA_API_KEY')); // your quota

file_put_contents('label.png',
    $c->renderPng('^XA^FO50,50^A0N,40,40^FDHello^FS^XZ'));

$report = $c->validate($zpl);
echo $report['ozet']['error'], " errors\n";
```

Requires PHP 8.1+, `ext-curl` and `ext-json`. No packages.

## Backing off correctly

Quota responses have their own exception, because a caller that cannot
tell 429 from a generic failure either never retries or retries
immediately — and both are wrong.

```php
use Labelixa\QuotaExceededException;

try {
    $png = $c->renderPng($zpl);
} catch (QuotaExceededException $e) {
    sleep($e->retryAfter);   // the server's Retry-After
    // $e->action is "upgrade" or "addon" when the server suggests one
}
```

Every other failure raises `LabelixaException` carrying the server's own
message and the status code.

## Using your application's HTTP stack

The default transport is ext-curl. An application that already has an
HTTP client — with its own proxy settings, logging or connection pool —
hands it over instead of having a second one appear inside it:

```php
use Labelixa\Transport;

final class AppTransport implements Transport { /* ... */ }

$c = new Client(transport: new AppTransport());
```

## What the methods do

| Method | Endpoint |
|---|---|
| `renderPng` | `POST /v1/printers/{dpmm}dpmm/labels/{w}x{h}/{i}` (ZPL) or `/v1/{language}/render` |
| `renderPdf` | the same path with `Accept: application/pdf` |
| `validate` | `/v1/diagnostics` (ZPL) or `/v1/{language}/diagnostics` |
| `toEpl` | the render path with `Accept: application/epl` |
| `barcode` | `GET /v1/barcodes` |
| `detectLanguage` | `POST /v1/language-detect` |
| `compatibility` | `POST /v1/compatibility?model=…` |

Full reference: <https://labelixa.com/docs/api>.

## Things worth knowing

- **A preview is not a print guarantee.** The renderer draws what the
  code says. A printer adds firmware, resident fonts, media and
  calibration.
- **A PDF of all labels costs one quota unit per label** (a single page
  costs one). A server rule, stated rather than softened.
- **An invalid barcode is not a 4xx.** The server answers 200 with an
  error image and an `X-Warnings` header, so this client raises instead
  of handing back something that looks generated.
- **`compatibility` reports risk.** It is not an emulator and never says
  "this works".

## Self-hosted

```php
$c = new Client(baseUrl: 'https://labels.internal');
```

## License

MIT

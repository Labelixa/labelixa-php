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

## Reading a response

Response keys are stable wire names and most of them are Turkish; they
never change inside `/v1`. The English name of every key is listed at
<https://labelixa.com/docs/api#response-fields>. A real answer of
`$c->validate($zpl)` (`POST /v1/diagnostics`) for a label whose `^FD` field is not
closed (abridged only where marked):

```jsonc
{
  "diagnostics": [
    {
      "code": "ZPL2001",
      "severity": "warning",
      "message_key": "diag.zpl2001",
      "line": 1, "col": 19, "end_line": 1, "end_col": 27,
      "args": { "komut": "^FD" },           // komut: command
      "quickfix": {                          // a one-click fix
        "offset": 26, "end_offset": 26,
        "yeni": "^FS"                        // yeni: replacement text to insert at offset
        // (abridged: also a short English title and its message key)
      },
      // mesaj: message (always English)
      "mesaj": "^FD field data is not terminated with ^FS; real printers may misread the commands that follow",
      "onizleme": { "x": 50, "y": 50 },      // onizleme: preview position, in dots
      "url": "https://labelixa.com/zpl/rules/ZPL2001"
    }
  ],
  "ozet": { "toplam": 1, "error": 0, "warning": 1, "info": 0 },  // ozet: summary, toplam: total
  "alanlar": [{ "line": 1, "x": 50, "y": 50 }],                 // alanlar: fields (each ^FD / ^FV)
  "rfid": []
}
```

An error is a status code plus a body. Render endpoints answer with one
line of plain text (`dpmm must be 6, 8, 12 or 24`); JSON endpoints carry
an English sentence and, where one exists, a stable machine-readable name
in `kod` (code) or `hata_tipi` (error type). When you read the raw body,
branch on that name, never on the sentence. This client throws `LabelixaException` with the status code and the sentence only; the status code is the stable signal it exposes.

```jsonc
// 401 Unauthorized
// WWW-Authenticate: ApiKey realm="labelixa"
{
  // (abridged: the English sentence "Invalid API key", which the client
  //  puts in the exception message)
  "kod": "gecersiz_anahtar"   // kod: code (the key sent belongs to no account)
}
```

When each error happens and how to fix it:
<https://labelixa.com/docs/api#errors>.

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

The API key is only sent over https: a client with a key and a plain
`http` base URL (other than `localhost`) throws
`InvalidArgumentException`. The default transport never follows
redirects, so a server cannot forward the key to another host.

## License

MIT

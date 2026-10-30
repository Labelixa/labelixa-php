<?php

declare(strict_types=1);

// PHPUnit is deliberately not used: the package has no runtime
// dependencies, and adding a dev dependency only for the tests would mean
// a `composer install` wait in CI. What is measured here is small and
// precise: does the thin client hit the RIGHT endpoint with the RIGHT
// headers.
//
// No network: a fake Transport captures every request.

require __DIR__ . '/../src/Contract.php';
require __DIR__ . '/../src/Transport.php';
require __DIR__ . '/../src/CurlTransport.php';
require __DIR__ . '/../src/LabelixaException.php';
require __DIR__ . '/../src/QuotaExceededException.php';
require __DIR__ . '/../src/Client.php';

use Labelixa\Client;
use Labelixa\Contract;
use Labelixa\LabelixaException;
use Labelixa\QuotaExceededException;
use Labelixa\Transport;

final class FakeTransport implements Transport
{
    public string $method = '';
    public string $url = '';
    /** @var array<string, string> */
    public array $headers = [];
    public ?string $body = null;
    public int $calls = 0;

    /**
     * @param array<string, string> $responseHeaders
     */
    public function __construct(
        private int $status = 200,
        private string $response = 'OK',
        private array $responseHeaders = [],
    ) {
    }

    public function send(
        string $method,
        string $url,
        array $headers,
        ?string $body,
    ): array {
        $this->calls++;
        $this->method = $method;
        $this->url = $url;
        $this->headers = $headers;
        $this->body = $body;

        return [
            'status' => $this->status,
            'body' => $this->response,
            'headers' => $this->responseHeaders,
        ];
    }
}

$passed = 0;
$failed = [];

function test(string $name, callable $body): void
{
    global $passed, $failed;
    try {
        $body();
        $passed++;
    } catch (Throwable $e) {
        $failed[] = $name . ' -> ' . $e->getMessage();
    }
}

function assertSame(mixed $expected, mixed $actual, string $note = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            '%sexpected %s, got %s',
            $note === '' ? '' : $note . ': ',
            var_export($expected, true),
            var_export($actual, true),
        ));
    }
}

const ZPL = '^XA^FO50,50^A0N,40,40^FDHello^FS^XZ';

test('render ZPL uses the Labelary-shaped path', function (): void {
    $t = new FakeTransport(200, "\x89PNG");
    $c = new Client(apiKey: 'lbx_test', baseUrl: 'https://example.test',
        clientName: 'suite/1', transport: $t);
    assertSame("\x89PNG", $c->renderPng(ZPL));
    assertSame('https://example.test/v1/printers/8dpmm/labels/4x6/0', $t->url);
    assertSame(ZPL, $t->body, 'code must be sent verbatim');
    assertSame('lbx_test', $t->headers['X-API-Key']);
    assertSame('suite/1', $t->headers['X-Client']);
    assertSame('text/plain', $t->headers['Content-Type']);
});

test('fractional size keeps its decimals, integer is plain', function (): void {
    $t = new FakeTransport();
    (new Client(baseUrl: 'https://o.test', transport: $t))
        ->renderPng(ZPL, widthIn: 2.25, heightIn: 4);
    assertSame('https://o.test/v1/printers/8dpmm/labels/2.25x4/0', $t->url);
});

test('rotation header is only sent when asked', function (): void {
    $t = new FakeTransport();
    $c = new Client(baseUrl: 'https://o.test', transport: $t);
    $c->renderPng(ZPL);
    assertSame(false, isset($t->headers['X-Rotation']));
    $c->renderPng(ZPL, rotation: 90);
    assertSame('90', $t->headers['X-Rotation']);
});

test('EPL/TSPL/CPCL use their own endpoint', function (): void {
    foreach (['epl', 'tspl', 'cpcl'] as $language) {
        $t = new FakeTransport();
        (new Client(baseUrl: 'https://o.test', transport: $t))
            ->renderPng("N\nP1\n", language: $language, index: 2);
        assertSame("https://o.test/v1/{$language}/render?index=2", $t->url);
    }
});

test('unknown language never reaches the network', function (): void {
    $t = new FakeTransport();
    try {
        (new Client(transport: $t))->renderPng(ZPL, language: 'pcl');
        throw new RuntimeException('unknown language was accepted');
    } catch (LabelixaException $e) {
        assertSame(0, $t->calls, 'no request should have been sent');
        assertSame(true, str_contains($e->getMessage(), 'pcl'));
    }
});

test('PDF of all labels drops the index', function (): void {
    $t = new FakeTransport();
    (new Client(baseUrl: 'https://o.test', transport: $t))
        ->renderPdf(ZPL, index: null);
    assertSame('https://o.test/v1/printers/8dpmm/labels/4x6/', $t->url);
    assertSame('application/pdf', $t->headers['Accept']);
});

test('validate sends the parameters the server READS', function (): void {
    $t = new FakeTransport(200, '{"ozet":{"error":0}}');
    $report = (new Client(baseUrl: 'https://o.test', transport: $t))
        ->validate(ZPL, dpmm: 12, widthIn: 3, heightIn: 2);
    assertSame(0, $report['ozet']['error']);
    assertSame('https://o.test/v1/diagnostics?dpmm=12&w=3&h=2', $t->url);
});

test('quota response is a DISTINCT exception carrying delay and hint', function (): void {
    $t = new FakeTransport(429, 'daily quota used',
        ['retry-after' => '42', 'x-quota-action' => 'upgrade']);
    try {
        (new Client(transport: $t))->renderPng(ZPL);
        throw new RuntimeException('quota response was swallowed');
    } catch (QuotaExceededException $e) {
        assertSame(42, $e->retryAfter);
        assertSame('upgrade', $e->action);
        assertSame(true, str_contains($e->getMessage(), 'daily quota used'));
    }
});

test('missing Retry-After falls back to the default', function (): void {
    $t = new FakeTransport(402, '');
    try {
        (new Client(transport: $t))->renderPng(ZPL);
        throw new RuntimeException('402 was swallowed');
    } catch (QuotaExceededException $e) {
        assertSame(60, $e->retryAfter);
        assertSame(null, $e->action);
    }
});

test('400 is not a quota error and keeps the server message', function (): void {
    $t = new FakeTransport(400, 'label too large');
    try {
        (new Client(transport: $t))->renderPng(ZPL);
        throw new RuntimeException('400 was swallowed');
    } catch (QuotaExceededException $e) {
        throw new RuntimeException('400 became a quota exception');
    } catch (LabelixaException $e) {
        assertSame(400, $e->status);
        assertSame(true, str_contains($e->getMessage(), 'label too large'));
    }
});

test('a JSON error answer keeps only the server sentence', function (): void {
    $t = new FakeTransport(400, json_encode([Contract::ERROR_FIELD => 'Empty body']));
    try {
        (new Client(transport: $t))->renderPng(ZPL);
        throw new RuntimeException('400 was swallowed');
    } catch (LabelixaException $e) {
        assertSame('Empty body', $e->serverMessage);
        assertSame('HTTP 400: Empty body', $e->getMessage());
    }
    assertSame('{"x":1}', Contract::serverText('{"x":1}'), 'other JSON passes through');
    assertSame(500, strlen(Contract::serverText(str_repeat('y', 900))));
});

test('the error carries the machine-readable code and error type', function (): void {
    $t = new FakeTransport(403, json_encode([Contract::ERROR_FIELD => 'Scope missing',
        Contract::CODE_FIELD => 'scope_missing']));
    try {
        (new Client(transport: $t))->renderPng(ZPL);
        throw new RuntimeException('403 was swallowed');
    } catch (LabelixaException $e) {
        assertSame('scope_missing', $e->getErrorCode());
        assertSame(null, $e->getErrorType());
        assertSame('Scope missing', $e->serverMessage);
    }
    $t = new FakeTransport(402, json_encode([Contract::ERROR_FIELD => 'Trial over',
        Contract::ERROR_TYPE_FIELD => 'TrialOver']), ['retry-after' => '5']);
    try {
        (new Client(transport: $t))->renderPng(ZPL);
        throw new RuntimeException('402 was swallowed');
    } catch (QuotaExceededException $e) {
        assertSame(null, $e->getErrorCode());
        assertSame('TrialOver', $e->getErrorType());
        assertSame(5, $e->retryAfter);
    }
    $odd = json_encode([Contract::CODE_FIELD => 7, Contract::ERROR_TYPE_FIELD => '']);
    foreach (['plain text', $odd, json_encode([Contract::CODE_FIELD])] as $body) {
        try {
            (new Client(transport: new FakeTransport(400, $body)))->renderPng(ZPL);
            throw new RuntimeException('400 was swallowed');
        } catch (LabelixaException $e) {
            assertSame(null, $e->getErrorCode(), $body);
            assertSame(null, $e->getErrorType(), $body);
        }
    }
    $old = new LabelixaException(400, 'm');
    assertSame(null, $old->getErrorCode());
    assertSame('HTTP 400: m', $old->getMessage());
    assertSame(null, (new QuotaExceededException(429, 'm', 60, null))->getErrorType());
});

test('barcode warning is an error, NOT a barcode', function (): void {
    // The server returns 200 + an error image + X-Warnings; handing that
    // back as "generated" would lie to the caller.
    $t = new FakeTransport(200, '<svg/>',
        ['x-warnings' => 'invalid data for ean13']);
    try {
        (new Client(transport: $t))->barcode('abc', type: 'ean13');
        throw new RuntimeException('warning was swallowed');
    } catch (LabelixaException $e) {
        assertSame(true, str_contains($e->getMessage(), 'invalid data for ean13'));
    }
});

test('barcode defaults and query string', function (): void {
    $t = new FakeTransport(200, '<svg/>');
    $output = (new Client(baseUrl: 'https://o.test', transport: $t))
        ->barcode('12345');
    assertSame('<svg/>', $output);
    assertSame('GET', $t->method);
    foreach (['type=code128', 'data=12345', 'format=svg'] as $part) {
        assertSame(true, str_contains($t->url, $part), $part);
    }
});

test('EPL translation asks for the EPL media type', function (): void {
    $t = new FakeTransport(200, "N\nP1\n");
    (new Client(baseUrl: 'https://o.test', transport: $t))->toEpl(ZPL);
    assertSame('application/epl', $t->headers['Accept']);
    assertSame('https://o.test/v1/printers/8dpmm/labels/4x6/', $t->url);
});

test('compatibility passes the model through unchanged', function (): void {
    $t = new FakeTransport(200, '{"risk":[]}');
    (new Client(baseUrl: 'https://o.test', transport: $t))
        ->compatibility(ZPL, 'zebra/zd421');
    assertSame(true, str_contains($t->url, 'model=zebra%2Fzd421'), $t->url);
});

test('anonymous client sends NO key header', function (): void {
    $t = new FakeTransport();
    (new Client(transport: $t))->renderPng(ZPL);
    assertSame(false, isset($t->headers['X-API-Key']));
    assertSame(false, isset($t->headers['X-Client']));
});

test('trailing slash in the base URL is not doubled', function (): void {
    $t = new FakeTransport();
    (new Client(baseUrl: 'https://o.test/', transport: $t))->renderPng(ZPL);
    assertSame('https://o.test/v1/printers/8dpmm/labels/4x6/0', $t->url);
});

test('non-JSON response does not pass silently', function (): void {
    $t = new FakeTransport(200, '<html>maintenance</html>');
    try {
        (new Client(transport: $t))->detectLanguage(ZPL);
        throw new RuntimeException('non-JSON response was accepted');
    } catch (LabelixaException $e) {
        assertSame(true, str_contains($e->getMessage(), 'not JSON'));
    }
});

test('the key only travels over https or to loopback', function (): void {
    foreach (['https://api.labelixa.com', 'http://127.0.0.1:8000',
              'http://localhost:8000', 'http://[::1]:8000'] as $ok) {
        new Client(apiKey: 'lbx_test', baseUrl: $ok, transport: new FakeTransport());
    }
    foreach (['http://api.labelixa.com', 'http://10.0.0.5',
              'http://localhost.evil.test'] as $bad) {
        try {
            new Client(apiKey: 'lbx_test', baseUrl: $bad, transport: new FakeTransport());
            throw new RuntimeException("key accepted for {$bad}");
        } catch (InvalidArgumentException $e) {
            assertSame(true, str_contains($e->getMessage(), 'https'));
        }
    }
    new Client(baseUrl: 'http://x', transport: new FakeTransport());   // anonymous
});

test('the curl transport does not follow redirects', function (): void {
    // A real local server that answers 307 to another port: the target
    // must never be contacted. Needs pcntl (not on Windows).
    if (!function_exists('pcntl_fork')) {
        return;
    }
    $target = stream_socket_server('tcp://127.0.0.1:0');
    $hop = stream_socket_server('tcp://127.0.0.1:0');
    $targetPort = (int) substr(strrchr(stream_socket_get_name($target, false), ':'), 1);
    $hopPort = (int) substr(strrchr(stream_socket_get_name($hop, false), ':'), 1);
    $pid = pcntl_fork();
    if ($pid === 0) {
        $conn = stream_socket_accept($hop, 5);
        fread($conn, 65536);
        fwrite($conn, "HTTP/1.1 307 Temporary Redirect\r\nLocation: "
            . "http://127.0.0.1:{$targetPort}/x\r\nContent-Length: 0\r\n"
            . "Connection: close\r\n\r\n");
        fclose($conn);
        exit(0);
    }
    $c = new Client(apiKey: 'lbx_test', baseUrl: "http://127.0.0.1:{$hopPort}",
        timeout: 5);
    try {
        $c->renderPng(ZPL);
        throw new RuntimeException('a redirect was followed');
    } catch (LabelixaException $e) {
        assertSame(307, $e->status);
    } finally {
        pcntl_waitpid($pid, $status);
    }
    stream_set_blocking($target, false);
    assertSame(false, @stream_socket_accept($target, 0), 'redirect target contacted');
});

printf("%d tests passed\n", $passed);
if ($failed !== []) {
    foreach ($failed as $line) {
        fwrite(STDERR, "FAILED: {$line}\n");
    }
    exit(1);
}

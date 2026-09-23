<?php

declare(strict_types=1);

namespace Labelixa;

/**
 * Thin PHP client for the Labelixa REST API: render, validate and
 * convert thermal printer label code (ZPL, EPL, TSPL, CPCL) without a
 * printer.
 *
 *     $c = new Client();                        // anonymous
 *     $c = new Client(apiKey: 'lbx_...');       // account quota
 *     $png = $c->renderPng($zpl);
 *     $report = $c->validate($zpl);
 *
 * Design notes, shared with the Python, Node and Go clients:
 *
 * - THIN wrapper. Every method maps 1:1 to a documented REST endpoint.
 *   No client-side magic and no hidden retries: a retry that swallows a
 *   quota response turns a clear signal into a slow mystery.
 *   https://labelixa.com/docs/api is the source of truth.
 * - Errors carry the server's own message. Quota exhaustion is its own
 *   exception with the retry delay and the server's action hint.
 * - No dependencies. The default transport is built on ext-curl, which
 *   every PHP install that talks to an API already has; an application
 *   with its own HTTP stack passes a Transport instead.
 */
final class Client
{
    public const VERSION = '0.1.0';
    public const DEFAULT_BASE_URL = 'https://api.labelixa.com';

    /** Printer languages the API renders and lints; ZPL is the default. */
    public const LANGUAGES = ['zpl', 'epl', 'tspl', 'cpcl'];

    private string $baseUrl;

    private Transport $transport;

    /** @var array<string, string> */
    private array $headers;

    /**
     * @param string|null $apiKey     lbx_ key; null means anonymous
     *                                (free, rate limited per IP)
     * @param string|null $baseUrl    point at your own on-premise instance
     * @param string|null $clientName sent as X-Client, e.g. "erp/2.1", so
     *                                usage is attributed to the
     *                                integration and never to a person
     * @param int            $timeout   seconds; rendering a large label
     *                                   takes seconds, so the default has
     *                                   room
     * @param Transport|null $transport  supply your application's own HTTP
     *                                   stack; the default uses ext-curl
     */
    public function __construct(
        ?string $apiKey = null,
        ?string $baseUrl = null,
        ?string $clientName = null,
        int $timeout = 60,
        ?Transport $transport = null,
    ) {
        $this->transport = $transport ?? new CurlTransport($timeout);
        $this->baseUrl = rtrim($baseUrl ?? self::DEFAULT_BASE_URL, '/');
        $this->headers = ['User-Agent' => 'labelixa-php/' . self::VERSION];
        if ($apiKey !== null && $apiKey !== '') {
            $this->headers['X-API-Key'] = $apiKey;
        }
        if ($clientName !== null && $clientName !== '') {
            $this->headers['X-Client'] = $clientName;
        }
    }

    /**
     * Renders one label to PNG and returns the raw bytes.
     *
     * Density and size apply to ZPL. For EPL, TSPL and CPCL the label
     * size comes from the code itself and only the index applies.
     */
    public function renderPng(
        string $code,
        string $language = 'zpl',
        int $dpmm = 8,
        float $widthIn = 4,
        float $heightIn = 6,
        int $index = 0,
        int $rotation = 0,
    ): string {
        $language = $this->checkLanguage($language);
        if ($language === 'zpl') {
            $extra = $rotation !== 0 ? ['X-Rotation' => (string) $rotation] : [];
            $path = sprintf(
                '/v1/printers/%ddpmm/labels/%sx%s/%d',
                $dpmm,
                self::number($widthIn),
                self::number($heightIn),
                $index,
            );

            return $this->request('POST', $path, $code, $extra)['body'];
        }

        return $this->request(
            'POST',
            sprintf('/v1/%s/render?index=%d', $language, $index),
            $code,
        )['body'];
    }

    /**
     * Renders ZPL to PDF. $index === null puts every label of the code in
     * one stream, which costs one quota unit PER LABEL (a single page
     * costs one). That is a server rule; this client states it rather
     * than softening it.
     */
    public function renderPdf(
        string $zpl,
        int $dpmm = 8,
        float $widthIn = 4,
        float $heightIn = 6,
        ?int $index = 0,
    ): string {
        $path = sprintf(
            '/v1/printers/%ddpmm/labels/%sx%s/%s',
            $dpmm,
            self::number($widthIn),
            self::number($heightIn),
            $index === null ? '' : (string) $index,
        );

        return $this->request('POST', $path, $zpl,
            ['Accept' => 'application/pdf'])['body'];
    }

    /**
     * Lints label code and returns the server's structured report: a
     * diagnostics list plus a summary with error, warning and info
     * counts.
     *
     * The report comes back as a decoded array rather than a typed
     * object on purpose. The server owns that shape and adds fields over
     * time; a fixed class here would silently drop whatever this version
     * has not heard of.
     *
     * @return array<string, mixed>
     */
    public function validate(
        string $code,
        string $language = 'zpl',
        int $dpmm = 8,
        float $widthIn = 4,
        float $heightIn = 6,
    ): array {
        $language = $this->checkLanguage($language);
        // The ZPL endpoint reads the label size from w and h. Any other
        // spelling is ignored silently by the server, so every check
        // would quietly run against the 4x6 default.
        $path = $language === 'zpl'
            ? sprintf(
                '/v1/diagnostics?dpmm=%d&w=%s&h=%s',
                $dpmm,
                self::number($widthIn),
                self::number($heightIn),
            )
            : sprintf('/v1/%s/diagnostics', $language);

        return $this->json('POST', $path, $code);
    }

    /**
     * Translates ZPL to EPL2. Fields the translation cannot carry over
     * surface in the warnings of the returned document.
     */
    public function toEpl(
        string $zpl,
        int $dpmm = 8,
        float $widthIn = 4,
        float $heightIn = 6,
    ): string {
        $path = sprintf(
            '/v1/printers/%ddpmm/labels/%sx%s/',
            $dpmm,
            self::number($widthIn),
            self::number($heightIn),
        );

        return $this->request('POST', $path, $zpl,
            ['Accept' => 'application/epl'])['body'];
    }

    /**
     * Generates a standalone barcode; returns SVG text or PNG bytes.
     *
     * Server contract worth knowing: invalid input is NOT a 4xx. The
     * server answers 200 with an error image and an X-Warnings header.
     * Handing that back as a generated barcode would be lying to the
     * caller, so it becomes an exception carrying the warning verbatim.
     */
    public function barcode(
        string $data,
        string $type = 'code128',
        string $format = 'svg',
    ): string {
        $query = http_build_query(
            ['type' => $type, 'data' => $data, 'format' => $format],
        );
        $res = $this->request('GET', '/v1/barcodes?' . $query);
        $warning = $res['headers']['x-warnings'] ?? null;
        if ($warning !== null && $warning !== '') {
            throw new LabelixaException(
                $res['status'],
                'Barcode not generated: ' . $warning,
            );
        }

        return $res['body'];
    }

    /**
     * Detects the printer language of raw label code.
     *
     * The result carries a confidence TIER (high, medium, low), not a
     * probability: the server does not compute one and this client does
     * not invent one.
     *
     * @return array<string, mixed>
     */
    public function detectLanguage(string $code): array
    {
        return $this->json('POST', '/v1/language-detect', $code);
    }

    /**
     * Analyses ZPL against a printer model given as manufacturer/model,
     * for example "zebra/zd421". The server reads Manufacturer/Model
     * case-insensitively; "zebra-zd421" is not a valid key and comes
     * back as the server's own 404.
     *
     * It reports RISK. It is not an emulator and never says "this works".
     *
     * @return array<string, mixed>
     */
    public function compatibility(string $zpl, string $model): array
    {
        return $this->json(
            'POST',
            '/v1/compatibility?' . http_build_query(['model' => $model]),
            $zpl,
        );
    }

    /**
     * Formats a dimension the way the API path expects: 4 not 4.0, and
     * 2.25 unchanged. "4.0x6.0" is a different address from "4x6".
     */
    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }

    private function checkLanguage(string $language): string
    {
        if (!in_array($language, self::LANGUAGES, true)) {
            throw new LabelixaException(0, sprintf(
                'Unknown language "%s"; expected one of %s',
                $language,
                implode(', ', self::LANGUAGES),
            ));
        }

        return $language;
    }

    /**
     * @param array<string, string> $extraHeaders
     *
     * @return array<string, mixed>
     */
    private function json(string $method, string $path, string $body): array
    {
        $decoded = json_decode(
            $this->request($method, $path, $body)['body'],
            true,
        );
        if (!is_array($decoded)) {
            throw new LabelixaException(0, 'Response was not JSON');
        }

        return $decoded;
    }

    /**
     * @param array<string, string> $extraHeaders
     *
     * @return array{status: int, body: string, headers: array<string, string>}
     */
    private function request(
        string $method,
        string $path,
        ?string $body = null,
        array $extraHeaders = [],
    ): array {
        $headers = $this->headers;
        if ($body !== null) {
            $headers['Content-Type'] = 'text/plain';
        }
        foreach ($extraHeaders as $name => $value) {
            $headers[$name] = $value;
        }

        $res = $this->transport->send(
            $method,
            $this->baseUrl . $path,
            $headers,
            $body,
        );

        if ($res['status'] !== 200) {
            // The message is capped: an error is a sentence, and a broken
            // proxy can answer with a megabyte of HTML.
            $message = substr($res['body'], 0, 500);
            if ($res['status'] === 402 || $res['status'] === 429) {
                throw new QuotaExceededException(
                    $res['status'],
                    $message,
                    (int) ($res['headers']['retry-after'] ?? 60),
                    $res['headers']['x-quota-action'] ?? null,
                );
            }
            throw new LabelixaException($res['status'], $message);
        }

        return $res;
    }
}

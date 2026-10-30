# Changelog

All notable changes to this package are documented here. The format
follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the
project uses [semantic versioning](https://semver.org/spec/v2.0.0.html).

## [0.2.0] - 2026-10-30

### Changed
- `LabelixaException::$serverMessage` carries the server's sentence only.
  The API answers an error with a small JSON object; the client used to
  pass that JSON through as it came (`HTTP 400: {"...": "..."}`). A body
  that is not JSON is still passed through as it came, capped at 500
  bytes.

### Added
- `Labelixa\Contract::serverText()`: the same extraction, for callers
  that read error bodies themselves.
- `LabelixaException::getErrorCode()` and `getErrorType()`: the
  machine-readable fields of an error answer. The code identifies the
  error (stable, unlike the sentence); the error type names the error
  class of print-job and trial errors. Both return `null` when the body
  is not JSON or the field is absent. The constructors take them as
  optional last arguments, so existing calls keep working. (Not
  `getCode()`: that is PHP's built-in, final integer exception code.)

### Security
- The API key is only sent over https. A `Client` with a key and a plain
  `http` base URL throws `InvalidArgumentException`, except for
  `localhost` and loopback addresses (local development).
- The curl transport sets `CURLOPT_FOLLOWLOCATION` to `false` explicitly,
  so a redirect can never carry the `X-API-Key` header to another host.

## [0.1.0] - 2026-09-22

### Added
- `Client` with `renderPng`, `renderPdf`, `validate`, `toEpl`,
  `barcode`, `detectLanguage` and `compatibility`, each mapping to one
  documented REST endpoint.
- `QuotaExceededException` for 402 and 429, carrying the retry delay and
  the server's action hint.
- `Transport` interface so an application can hand over its own HTTP
  stack; the default is built on ext-curl and pulls in no packages.

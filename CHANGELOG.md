# Changelog

All notable changes to this package are documented here. The format
follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the
project uses [semantic versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2026-09-22

### Added
- `Client` with `renderPng`, `renderPdf`, `validate`, `toEpl`,
  `barcode`, `detectLanguage` and `compatibility`, each mapping to one
  documented REST endpoint.
- `QuotaExceededException` for 402 and 429, carrying the retry delay and
  the server's action hint.
- `Transport` interface so an application can hand over its own HTTP
  stack; the default is built on ext-curl and pulls in no packages.

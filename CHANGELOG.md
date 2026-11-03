# Changelog

All notable changes to this plugin are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the plugin uses
[Semantic Versioning](https://semver.org/).

## [1.0.0] - 2026-11-03

### Added

- TeePay hosted checkout through `POST /v1/checkout-sessions`, with idempotency keys derived from the order.
- Optional mobile money prompt through `POST /v1/collections`, with Tanzanian mobile number validation.
- Payment confirmation only from authenticated status reads (`GET /v1/checkout-sessions/{id}`, `GET /v1/collections/{reference}`) with amount, currency, reference and environment checks.
- Signed webhook endpoint (`/wc-api/teepay_webhook/`) with HMAC-SHA256 verification, a five minute replay window and per-order event de-duplication.
- Return endpoint, order received status polling and a 15 minute reconciliation job.
- Orders whose TeePay amount or currency differs are put on hold for review.
- Admin notice for `account_restricted`; customers see a generic "unavailable" message.
- Cart and Checkout blocks support and HPOS compatibility.
- Secret settings stored as password fields that are never printed back.
- Debug logging through `WC_Logger` with keys, secrets and phone numbers removed.
- Translation template, uninstall cleanup, PHPUnit tests and GitHub Actions CI.

### Not supported

- Refunds: the public TeePay API does not offer them yet.

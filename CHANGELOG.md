# Changelog

All notable changes to this plugin are recorded here. The public changelog on
oxywp.com is generated from `readme.txt`, which follows this file.

## [Unreleased]

### Sprint 1 — foundations (12/08/2026)

- Plugin bootstrap, PSR-4 autoloader, WooCommerce and HPOS compatibility
  declaration, requirements check with an explanation instead of a fatal.
- Schema: the eight tables, created and versioned through `dbDelta()`.
- Seven capabilities, granted to administrators and shop managers on activation
  **and on update**.
- Supplier records: domain object, validation rules that return codes, storage,
  and the admin screen to list, search, add, edit, deactivate and delete them.
- A supplier named on a purchase order cannot be deleted, only deactivated.
- Audit log, written from the first change onwards.
- Non-destructive uninstall: the data stays unless an administrator asks for it
  to go.
- Plugin icon (128 and 256 px) in `.wordpress-org/`.
- 29 unit tests and 23 checks inside a real WordPress (schema, capabilities,
  storage), PHPCS clean, PHPStan at level 8 clean, CI on GitHub Actions
  including a job that builds the package and inspects it.
- Tested against WordPress 7.0 and WooCommerce 11: the integration suite runs on
  it in CI, and the test bench at test.44123.it/oxysuppliers runs 7.0.4 with
  HPOS on, where 41 checks drive the admin screens over HTTP.

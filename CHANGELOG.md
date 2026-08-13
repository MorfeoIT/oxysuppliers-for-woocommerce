# Changelog

All notable changes to this plugin are recorded here. The public changelog on
oxywp.com is generated from `readme.txt`, which follows this file.

## [Unreleased]

### Sprint 2 — product to supplier (13/08/2026)

- Price lists: each product or variation can be linked to any number of
  suppliers, each with their own code, cost, minimum, order multiple, pack size
  and lead time.
- `OrderTerms`, which turns a need into a quantity the supplier will actually
  accept. Minimum, multiple and pack size are three constraints that all have to
  hold at once — packs of six and multiples of ten leave only the multiples of
  thirty — and nothing is ever rounded down.
- Preferred supplier per article, with the cheapest standing in when nobody has
  been chosen, so a shop with one supplier never has to tick a box.
- A **Suppliers** panel on the product screen and on every variation, with no
  JavaScript at all.
- Saving the same supplier for the same article twice is an edit, not a second
  line, in the code and in the database.
- The list of suppliers now tells an empty shop apart from a search that found
  nothing.
- 51 unit tests, 35 checks inside WordPress, 50 checks driving the admin screens
  over HTTP on the test bench, plus 21 that drive the product panel the way the
  product screen does.
- Test bench at test.44123.it/oxysuppliers: WordPress 7.0.4, WooCommerce 11,
  HPOS on, seeded with a small but real shop. `scripts/deploy-test-site.ps1`,
  `scripts/verify-http.sh` and `scripts/verify-product-panel.php`.

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
